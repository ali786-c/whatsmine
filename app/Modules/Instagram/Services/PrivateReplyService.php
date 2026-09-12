<?php

namespace App\Modules\Instagram\Services;

use App\Modules\Instagram\Exceptions\InstagramGraphException;
use App\Modules\Instagram\Models\CommentAutomationLog;
use App\Modules\Instagram\Models\FunnelParticipant;
use App\Modules\Instagram\Models\InstagramAccount;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Single gateway for sending the ONE private reply Meta allows per comment.
 * Guards: participant stage, 7-day window, per-account send rate limit.
 *
 * Throw/no-throw contract: transient failures (throttling, 5xx, network) are
 * RE-THROWN so the queue retries with backoff — the participant stays in
 * "commented" and the send is resumed via a duplicate webhook delivery or the
 * timeout job's stranded-send sweep. Permanent 4xx rejections are swallowed
 * and returned as ok=false (retrying can never succeed).
 */
class PrivateReplyService
{
    public function __construct(
        private readonly InstagramGraphClient $client,
        private readonly InboxMirrorService $mirror,
    ) {}

    /**
     * @return array{ok: bool, message_id: ?string, error: ?string}
     */
    public function send(FunnelParticipant $participant, string $text): array
    {
        $account = $participant->account;
        $log = fn (string $action, array $extra = []) => CommentAutomationLog::create(array_merge([
            'workspace_id' => $participant->workspace_id,
            'instagram_account_id' => $participant->instagram_account_id,
            'automation_id' => $participant->automation_id,
            'funnel_participant_id' => $participant->id,
            'comment_id' => $participant->comment_id,
            'stage' => $participant->stage,
        ], $extra));

        if ($participant->stage !== FunnelParticipant::STAGE_COMMENTED) {
            $log(CommentAutomationLog::ACTION_SKIPPED, ['error' => "stage={$participant->stage} — private reply already handled"]);

            return ['ok' => false, 'message_id' => null, 'error' => 'wrong_stage'];
        }

        if (! $participant->privateReplyWindowOpen()) {
            $participant->forceFill(['stage' => FunnelParticipant::STAGE_EXPIRED, 'closed_at' => now()])->save();
            $log(CommentAutomationLog::ACTION_EXPIRED, ['error' => '7-day private reply window elapsed']);

            return ['ok' => false, 'message_id' => null, 'error' => 'window_expired'];
        }

        if (! $this->acquireSendSlot($account)) {
            $log(CommentAutomationLog::ACTION_RATE_LIMITED, ['error' => 'per-account send rate limit reached']);

            // Throwing (code 4 = Graph throttling) makes the queue retry with
            // backoff; the participant stays in "commented" and the crash-resume
            // path re-attempts the send. Returning ok=false here would strand the
            // funnel forever — no duplicate webhook arrives to resume it.
            throw new InstagramGraphException('Per-account send rate limit reached', graphErrorCode: 4, httpStatus: 429);
        }

        InstagramLog::send('info', 'sending private reply via Graph', ['participant_id' => $participant->id, 'comment_id' => $participant->comment_id, 'account_id' => $account->id, 'text_preview' => mb_substr($text, 0, 120)]);

        try {
            $res = $this->client->sendPrivateReply($account, $participant->comment_id, $text);

            $participant->forceFill([
                'stage' => FunnelParticipant::STAGE_DM_SENT,
                'private_reply_message_id' => (string) ($res['message_id'] ?? ''),
            ])->save();

            $log(CommentAutomationLog::ACTION_DM_SENT, ['response_json' => $res]);
            InstagramLog::send('info', 'private reply SENT', ['participant_id' => $participant->id, 'comment_id' => $participant->comment_id, 'message_id' => $res['message_id'] ?? null]);

            $this->mirror->mirrorOutbound($participant, $text, $res['message_id'] ?? null, $res);

            return ['ok' => true, 'message_id' => (string) ($res['message_id'] ?? ''), 'error' => null];
        } catch (InstagramGraphException $e) {
            if ($e->isTokenPermissionError()) {
                $account->markTokenExpired();
            }

            $log(CommentAutomationLog::ACTION_DM_FAILED, [
                'error' => $e->getMessage(),
                'response_json' => ['code' => $e->graphErrorCode, 'subcode' => $e->graphErrorSubcode, 'http' => $e->httpStatus],
            ]);

            InstagramLog::send($e->shouldRetry() ? 'warning' : 'error', 'private reply FAILED', [
                'participant_id' => $participant->id,
                'code' => $e->graphErrorCode,
                'subcode' => $e->graphErrorSubcode,
                'http' => $e->httpStatus,
                'retryable' => $e->shouldRetry(),
                'token_permission_error' => $e->isTokenPermissionError(),
                'error' => $e->getMessage(),
            ]);

            // Transient failures (5xx, throttling) must propagate so the queue
            // retries with backoff — the participant stays in "commented" and the
            // one-reply budget is untouched. Permanent 4xx validation errors are
            // swallowed here; they would fail identically on every retry.
            if ($e->shouldRetry()) {
                throw $e;
            }

            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function acquireSendSlot(InstagramAccount $account): bool
    {
        $limit = max(1, (int) config('instagram.rate_limit_per_minute', 30));

        return RateLimiter::attempt(
            key: 'instagram-send:'.$account->id,
            maxAttempts: $limit,
            callback: fn () => true,
            decaySeconds: 60,
        );
    }
}

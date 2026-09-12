<?php

namespace App\Modules\Instagram\Services;

use App\Modules\Instagram\Models\CommentAutomation;
use App\Modules\Instagram\Models\CommentAutomationLog;
use App\Modules\Instagram\Models\FunnelParticipant;
use App\Modules\Instagram\Models\InstagramAccount;
use Illuminate\Support\Facades\Log;

/**
 * The comment → DM → follow-gate → delivery state machine.
 *
 * Meta constraints enforced here and in the callers:
 *  - ONE private reply per comment: DB-unique comment_id + participant stage guard.
 *  - The single private reply carries everything (hook + follow ask + delivery)
 *    because no second message is allowed before the user replies.
 *  - Follow-ups only after the user replies and within the 24h window.
 */
class CommentFunnelService
{
    public function __construct(
        private readonly PrivateReplyService $privateReplies,
        private readonly LeadDeliveryService $deliveries,
        private readonly InboxMirrorService $mirror,
        private readonly InstagramGraphClient $client,
    ) {}

    /**
     * Handle one `comments` webhook value (either Meta payload shape, already
     * normalized by the webhook controller).
     *
     * @param  array<string, mixed>  $value
     */
    public function handleComment(string $entryId, array $value): void
    {
        // Business Login shape puts the comment id in `id`; FB Login in `comment_id`.
        $commentId = (string) ($value['comment_id'] ?? $value['id'] ?? '');
        $from = (array) ($value['from'] ?? []);
        $commenterId = (string) ($from['id'] ?? '');
        $username = $from['username'] ?? null;
        $text = (string) ($value['text'] ?? '');
        $media = (array) ($value['media'] ?? []);
        $mediaId = (string) ($media['id'] ?? '');
        $mediaType = (string) ($media['media_product_type'] ?? ($media['product_type'] ?? ''));

        // Audit row first (fail-open); workspace patched once the account is resolved.
        CommentAutomationLog::create([
            'workspace_id' => 0, // patched below once the account is resolved
            'comment_id' => $commentId ?: null,
            'action' => CommentAutomationLog::ACTION_RECEIVED,
            'request_json' => $value,
        ]);

        if ($commentId === '' || $commenterId === '' || $entryId === '') {
            Log::info('instagram_module: comment missing ids — dropped', ['entry_id' => $entryId]);

            return;
        }

        $account = InstagramAccount::where('ig_user_id', $entryId)->where('status', 'active')->first();
        if (! $account) {
            Log::info('instagram_module: comment for unconnected/inactive IG account', ['entry_id' => $entryId]);

            return;
        }

        CommentAutomationLog::where('comment_id', $commentId)
            ->where('workspace_id', 0)
            ->where('action', CommentAutomationLog::ACTION_RECEIVED)
            ->update(['workspace_id' => $account->workspace_id, 'instagram_account_id' => $account->id]);

        // Own comments (the account commenting on its own post) never trigger.
        if ($commenterId === $account->ig_user_id) {
            $this->log($account, null, $commentId, CommentAutomationLog::ACTION_IGNORED_OWN_COMMENT, $value);

            return;
        }

        // Replies to other comments are skipped (v1 triggers on top-level comments).
        if (filled($value['parent_id'] ?? null)) {
            $this->log($account, null, $commentId, CommentAutomationLog::ACTION_IGNORED_REPLY, $value);

            return;
        }

        // Hard dedupe — also the one-private-reply guarantee.
        $existing = FunnelParticipant::where('comment_id', $commentId)->first();
        if ($existing) {
            Log::info('instagram_module: duplicate comment event', ['comment_id' => $commentId]);

            return;
        }

        $automation = $this->pickAutomation($account, $text, $mediaType, $value);
        if (! $automation) {
            $this->log($account, null, $commentId, CommentAutomationLog::ACTION_NO_MATCH, $value);

            return;
        }

        $participant = FunnelParticipant::create([
            'workspace_id' => $account->workspace_id,
            'instagram_account_id' => $account->id,
            'automation_id' => $automation->id,
            'commenter_igsid' => $commenterId,
            'username' => $username,
            'comment_id' => $commentId,
            'media_id' => $mediaId ?: null,
            'media_product_type' => $mediaType ?: null,
            'stage' => FunnelParticipant::STAGE_COMMENTED,
            'expires_at' => now()->addDays(7), // Meta: private replies within 7 days
        ]);

        $this->log($account, $automation, $commentId, CommentAutomationLog::ACTION_MATCHED, $value, $participant);

        // Build the ONE allowed message: hook + follow ask + (delivery when no gate).
        $replyText = $this->renderReply($automation, $username, $participant);

        $sent = $this->privateReplies->send($participant, $replyText);
        if (! $sent['ok']) {
            return; // logged + classified inside the service
        }

        if ($automation->follow_gate) {
            $participant->forceFill(['stage' => FunnelParticipant::STAGE_AWAITING_FOLLOW])->save();
            $this->log($account, $automation, $commentId, CommentAutomationLog::ACTION_AWAITING_FOLLOW, [], $participant);
        } else {
            // No gate: the delivery was embedded in the private reply — mark done.
            $participant->forceFill([
                'stage' => FunnelParticipant::STAGE_DELIVERED,
                'delivered_at' => now(),
            ])->save();
        }
    }

    /**
     * Handle one `messaging` event (the participant replying in the DM thread).
     * Returns true when the funnel handled the event (caller must not forward it
     * to the Inbox pipeline), false when the event is not funnel-related.
     *
     * @param  array<string, mixed>  $event
     */
    public function handleDmReply(string $entryId, array $event): bool
    {
        $senderId = (string) data_get($event, 'sender.id', '');
        $mid = data_get($event, 'message.mid');
        $text = (string) data_get($event, 'message.text', '');

        if ($senderId === '' || data_get($event, 'message.is_echo') || ! isset($event['message'])) {
            return false; // echoes / reads / deliveries are not replies
        }

        $participant = FunnelParticipant::where('commenter_igsid', $senderId)
            ->where('stage', FunnelParticipant::STAGE_AWAITING_FOLLOW)
            ->orderByDesc('created_at')
            ->first();

        if (! $participant) {
            return false; // not a funnel thread — the Inbox pipeline handles normal DMs
        }

        $account = $participant->account;
        $automation = $participant->automation;

        if (! $participant->followUpWindowOpen()) {
            $participant->forceFill(['stage' => FunnelParticipant::STAGE_CLOSED, 'closed_at' => now()])->save();
            $this->log($account, $automation, $participant->comment_id, CommentAutomationLog::ACTION_CLOSED, $event, $participant, '24h follow-up window elapsed');

            return true;
        }

        // The reply OPENS the 24h window (idempotent) and is mirrored to the Inbox.
        if ($participant->dm_thread_opened_at === null) {
            $participant->forceFill(['dm_thread_opened_at' => now()])->save();
        }

        $this->mirror->mirrorInbound($participant, $text, $mid ? (string) $mid : null, $event);

        $keyword = trim((string) ($automation?->reply_keyword ?? ''));
        $keywordMatched = $keyword !== '' && KeywordMatcher::matches([$keyword], 'contains', $text);

        if ($keyword === '' || $keywordMatched) {
            $delivery = (array) ($automation?->delivery ?? []);
            $this->deliveries->deliver($participant, $delivery);

            return true;
        }

        // Polite nudge (bounded) reminding them of the exact keyword.
        $maxNudges = max(0, (int) config('instagram.max_nudges', 1));
        if ($participant->nudge_count < $maxNudges) {
            $participant->increment('nudge_count');
            $nudge = "Just reply {$keyword} and I'll send it right over! 😊";

            try {
                $this->client->sendMessage($account, $senderId, $nudge);
                $this->mirror->mirrorOutbound($participant, $nudge);
                $this->log($account, $automation, $participant->comment_id, CommentAutomationLog::ACTION_NUDGED, $event, $participant);
            } catch (\Throwable $e) {
                $this->log($account, $automation, $participant->comment_id, CommentAutomationLog::ACTION_SKIPPED, $event, $participant, 'nudge failed: '.$e->getMessage());
            }

            return true;
        }

        $this->log($account, $automation, $participant->comment_id, CommentAutomationLog::ACTION_SKIPPED, $event, $participant, 'nudge limit reached; keyword not matched');

        return true;
    }

    /**
     * First matching automation for this comment (priority, then newest).
     */
    private function pickAutomation(InstagramAccount $account, string $text, string $mediaType, array $value): ?CommentAutomation
    {
        return CommentAutomation::forMatching()
            ->where('instagram_account_id', $account->id)
            ->get()
            ->first(function (CommentAutomation $automation) use ($text, $mediaType, $value): bool {
                $filter = (array) ($automation->media_filter ?? []);
                if ($filter !== [] && $mediaType !== '' && ! in_array($mediaType, $filter, true)) {
                    return false;
                }

                return match ($automation->trigger_type) {
                    'all_comments' => true,
                    'keyword' => KeywordMatcher::matches($automation->keywords, (string) $automation->match_mode, $text),
                    'mention_only' => filled($value['mentioned'] ?? null) || str_contains($text, '@'),
                    default => false,
                };
            });
    }

    private function renderReply(CommentAutomation $automation, ?string $username, FunnelParticipant $participant): string
    {
        $text = (string) $automation->reply_message;

        if ($automation->follow_gate) {
            $keyword = trim((string) ($automation->reply_keyword ?? 'DONE'));
            $ask = trim((string) ($automation->follow_prompt_message ?? ''))
                ?: "Make sure you're following us, then reply with {$keyword} and I'll send it over!";

            $text = rtrim($text)."\n\n".$ask;
        } else {
            $text .= $this->deliveries->renderForPrivateReply((array) ($automation->delivery ?? []));
        }

        return str_replace(
            ['{username}', '{post_url}'],
            [$username ?: 'friend', ''],
            $text,
        );
    }

    private function log(
        InstagramAccount $account,
        ?CommentAutomation $automation,
        ?string $commentId,
        string $action,
        array $request,
        ?FunnelParticipant $participant = null,
        ?string $error = null,
    ): void {
        CommentAutomationLog::create([
            'workspace_id' => $account->workspace_id,
            'instagram_account_id' => $account->id,
            'automation_id' => $automation?->id,
            'funnel_participant_id' => $participant?->id,
            'comment_id' => $commentId,
            'action' => $action,
            'stage' => $participant?->stage,
            'request_json' => $request ?: null,
            'error' => $error,
        ]);
    }
}

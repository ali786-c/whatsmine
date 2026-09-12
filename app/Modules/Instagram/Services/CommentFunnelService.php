<?php

namespace App\Modules\Instagram\Services;

use App\Modules\Instagram\Models\CommentAutomation;
use App\Modules\Instagram\Models\CommentAutomationLog;
use App\Modules\Instagram\Models\FunnelParticipant;
use App\Modules\Instagram\Models\InstagramAccount;
use Illuminate\Database\QueryException;
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
        $participant = FunnelParticipant::where('comment_id', $commentId)->first();
        if ($participant) {
            // Crash-resume: if a previous attempt died after creating the row but
            // before the send completed (stage still "commented", no message id),
            // re-attempt the send instead of leaving the funnel stuck forever.
            if ($participant->stage === FunnelParticipant::STAGE_COMMENTED
                && blank($participant->private_reply_message_id)
                && $participant->automation) {
                $this->sendReply($participant, $participant->automation);
            }

            return;
        }

        $automation = $this->pickAutomation($account, $text, $mediaType, $mediaId ?: null, $value);
        if (! $automation) {
            $this->log($account, null, $commentId, CommentAutomationLog::ACTION_NO_MATCH, $value);

            return;
        }

        try {
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
        } catch (QueryException $e) {
            // Concurrent duplicate delivery lost the unique(comment_id) race — the
            // winner processes it; nothing left to do here.
            Log::info('instagram_module: concurrent duplicate comment', ['comment_id' => $commentId]);

            return;
        }

        $this->log($account, $automation, $commentId, CommentAutomationLog::ACTION_MATCHED, $value, $participant);

        $this->sendReply($participant, $automation);
    }

    /**
     * Re-attempt the private reply for a stranded participant and advance the
     * stage on success — the timeout job's self-healing entry point.
     */
    public function resumePrivateReply(FunnelParticipant $participant): void
    {
        $automation = $participant->automation;

        if (! $automation) {
            $participant->forceFill(['stage' => FunnelParticipant::STAGE_CLOSED, 'closed_at' => now()])->save();

            return;
        }

        $this->sendReply($participant, $automation);
    }

    /**
     * Render and send the ONE allowed private reply, then advance the stage:
     * follow-gate automations wait for the user's reply; ungated ones mark the
     * delivery as done (it was embedded in the single message).
     */
    private function sendReply(FunnelParticipant $participant, CommentAutomation $automation): void
    {
        $replyText = $this->renderReply($automation, $participant->username, $participant);

        $sent = $this->privateReplies->send($participant, $replyText);
        if (! $sent['ok']) {
            // Permanent (non-retryable) Graph rejection — close the funnel so it
            // does not sit as a phantom "commented" row until the 7-day sweep.
            // Rate-limited/transient failures throw instead and are retried by
            // the queue, resuming through the duplicate-comment path.
            if ($participant->stage === FunnelParticipant::STAGE_COMMENTED) {
                $participant->forceFill(['stage' => FunnelParticipant::STAGE_CLOSED, 'closed_at' => now()])->save();
            }

            return;
        }

        if ($automation->follow_gate) {
            $participant->forceFill(['stage' => FunnelParticipant::STAGE_AWAITING_FOLLOW])->save();
            $this->log($participant->account, $automation, $participant->comment_id, CommentAutomationLog::ACTION_AWAITING_FOLLOW, [], $participant);
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

        if (! $automation) {
            // The rule that created this funnel was deleted mid-flight — close the
            // participant instead of looping on a null delivery forever.
            $participant->forceFill(['stage' => FunnelParticipant::STAGE_CLOSED, 'closed_at' => now()])->save();
            $this->log($account, null, $participant->comment_id, CommentAutomationLog::ACTION_SKIPPED, $event, $participant, 'automation deleted while participant awaiting follow');

            return true;
        }

        // The user's FIRST reply OPENS the 24h window (Meta: follow-ups are only
        // possible after the user responds). A later reply that arrives after the
        // window already elapsed closes the funnel instead.
        if ($participant->dm_thread_opened_at === null) {
            $participant->forceFill(['dm_thread_opened_at' => now()])->save();
        } elseif (! $participant->followUpWindowOpen()) {
            $participant->forceFill(['stage' => FunnelParticipant::STAGE_CLOSED, 'closed_at' => now()])->save();
            $this->log($account, $automation, $participant->comment_id, CommentAutomationLog::ACTION_CLOSED, $event, $participant, '24h follow-up window elapsed');

            return true;
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
     * First matching automation for this comment.
     *
     * Precedence: post-scoped automations beat account-wide ones (a rule made
     * for THIS post should always win over a generic rule), then lower priority
     * number wins, then the newest.
     */
    private function pickAutomation(InstagramAccount $account, string $text, string $mediaType, ?string $mediaId, array $value): ?CommentAutomation
    {
        $candidates = CommentAutomation::forMatching()
            ->where('instagram_account_id', $account->id)
            ->get()
            ->filter(fn (CommentAutomation $automation) => $automation->appliesToMedia($mediaId))
            ->filter(fn (CommentAutomation $automation) => $this->triggerMatches($automation, $text, $mediaType, $value));

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates->sort(function (CommentAutomation $a, CommentAutomation $b): int {
            $aScoped = $a->isPostScoped() ? 0 : 1;
            $bScoped = $b->isPostScoped() ? 0 : 1;
            if ($aScoped !== $bScoped) {
                return $aScoped <=> $bScoped;
            }

            if ((int) $a->priority !== (int) $b->priority) {
                return $a->priority <=> $b->priority;
            }

            return $b->created_at->getTimestamp() <=> $a->created_at->getTimestamp();
        })->first();
    }

    /** Whether the automation's trigger fires for this comment. */
    private function triggerMatches(CommentAutomation $automation, string $text, string $mediaType, array $value): bool
    {
        $filter = (array) ($automation->media_filter ?? []);
        // A configured media filter must be respected even when the webhook
        // omits the media product type: treat "unknown" as non-matching
        // rather than silently bypassing the user's restriction.
        if ($filter !== [] && ! in_array($mediaType !== '' ? $mediaType : 'UNKNOWN', $filter, true)) {
            return false;
        }

        return match ($automation->trigger_type) {
            'all_comments' => true,
            'keyword' => KeywordMatcher::matches($automation->keywords, (string) $automation->match_mode, $text),
            'mention_only' => str_contains($text, '@'),
            default => false,
        };
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

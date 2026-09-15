<?php

namespace App\Modules\Instagram\Services;

use App\Modules\Instagram\Models\CommentAutomation;
use App\Modules\Instagram\Models\CommentAutomationLog;
use App\Modules\Instagram\Models\FunnelParticipant;
use App\Modules\Instagram\Models\InstagramAccount;
use Illuminate\Database\QueryException;

/**
 * The comment → DM → follow-gate → delivery state machine.
 *
 * Meta constraints enforced here and in the callers:
 *  - ONE private reply per comment: DB-unique comment_id + participant stage guard.
 *  - The single private reply carries everything (hook + follow ask + delivery)
 *    because no second message is allowed before the user replies.
 *  - Follow-ups only after the user replies and within the 24h window.
 *  - The gated loop ("no" → re-ask, "yes" → keyword reminder) stays inside the
 *    bounded nudge budget; an exhausted budget closes the funnel for agent handoff.
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
            InstagramLog::comment('info', 'comment missing ids — dropped', ['entry_id' => $entryId, 'comment_id' => $commentId, 'from_id' => $commenterId]);

            return;
        }

        $account = InstagramAccount::where('ig_user_id', $entryId)->where('status', 'active')->first();
        if (! $account) {
            InstagramLog::comment('info', 'comment for unconnected/inactive IG account — dropped', ['entry_id' => $entryId, 'comment_id' => $commentId]);

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
            InstagramLog::comment('info', 'concurrent duplicate comment — race lost, winner processing', ['comment_id' => $commentId, 'account_id' => $account->id]);

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

        InstagramLog::funnel('info', 'sending private reply', [
            'participant_id' => $participant->id,
            'comment_id' => $participant->comment_id,
            'automation_id' => $automation->id,
            'follow_gate' => (bool) $automation->follow_gate,
        ]);

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
            InstagramLog::funnel('info', 'stage → awaiting_follow (private reply sent, gate on)', ['participant_id' => $participant->id, 'comment_id' => $participant->comment_id]);
        } else {
            // No gate: the delivery was embedded in the private reply — mark done.
            $participant->forceFill([
                'stage' => FunnelParticipant::STAGE_DELIVERED,
                'delivered_at' => now(),
            ])->save();
            InstagramLog::funnel('info', 'stage → delivered (no gate, delivery embedded in private reply)', ['participant_id' => $participant->id, 'comment_id' => $participant->comment_id]);
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
        // A tapped quick reply arrives as quick_reply.payload with the button
        // TITLE in text — the payload ("DONE" / "no") is what the funnel logic
        // expects, so it wins whenever present.
        $text = (string) (data_get($event, 'message.quick_reply.payload') ?: data_get($event, 'message.text', ''));

        if ($senderId === '' || data_get($event, 'message.is_echo') || ! isset($event['message'])) {
            return false; // echoes / reads / deliveries are not replies
        }

        // entry.id is the Instagram account the DM was sent TO — scope the lookup
        // to it. Instagram-scoped IDs are per-account, so matching on the IGSID
        // alone could hijack the DM of one account for another account's funnel
        // (e.g. the same person comments/DMs two connected accounts and the newest
        // awaiting_follow participant silently swallows the message).
        $accountId = InstagramAccount::where('ig_user_id', $entryId)->value('id');

        $participant = $accountId === null
            ? null
            : FunnelParticipant::where('commenter_igsid', $senderId)
                ->where('instagram_account_id', $accountId)
                ->where('stage', FunnelParticipant::STAGE_AWAITING_FOLLOW)
                ->orderByDesc('created_at')
                ->first();

        if (! $participant) {
            InstagramLog::dm('info', 'DM reply is not a funnel thread — forwarded to Inbox pipeline', ['entry_id' => $entryId, 'sender_id' => $senderId]);

            return false; // not a funnel thread — the Inbox pipeline handles normal DMs
        }

        $account = $participant->account;
        $automation = $participant->automation;

        if (! $automation) {
            // The rule that created this funnel was deleted mid-flight — close the
            // participant instead of looping on a null delivery forever. The user's
            // message must still reach a human: mirror it onto the Inbox thread
            // instead of silently swallowing it.
            $participant->forceFill(['stage' => FunnelParticipant::STAGE_CLOSED, 'closed_at' => now()])->save();
            $this->log($account, null, $participant->comment_id, CommentAutomationLog::ACTION_SKIPPED, $event, $participant, 'automation deleted while participant awaiting follow');
            $this->mirror->mirrorInbound($participant, $text, $mid ? (string) $mid : null, $event);

            return true;
        }

        // The user's FIRST reply OPENS the 24h window (Meta: follow-ups are only
        // possible after the user responds). A later reply that arrives after the
        // window already elapsed closes the funnel instead.
        if ($participant->dm_thread_opened_at === null) {
            $participant->forceFill(['dm_thread_opened_at' => now()])->save();
            InstagramLog::dm('info', '24h follow-up window OPENED by participant reply', ['participant_id' => $participant->id, 'comment_id' => $participant->comment_id, 'username' => $participant->username]);
        } elseif (! $participant->followUpWindowOpen()) {
            // The funnel is over — but Meta opens a FRESH 24h window for every user
            // message, so this reply is from a live customer. Swallowing it strands
            // them forever: close the funnel AND mirror the message onto the Inbox
            // thread so a human agent can answer (Meta permits agent sends for the
            // next 24h from this user message).
            $participant->forceFill(['stage' => FunnelParticipant::STAGE_CLOSED, 'closed_at' => now()])->save();
            $this->log($account, $automation, $participant->comment_id, CommentAutomationLog::ACTION_CLOSED, $event, $participant, '24h follow-up window elapsed');
            InstagramLog::dm('warning', 'participant closed — 24h follow-up window elapsed (reply mirrored to Inbox for agent handoff)', ['participant_id' => $participant->id, 'comment_id' => $participant->comment_id]);
            $this->mirror->mirrorInbound($participant, $text, $mid ? (string) $mid : null, $event);

            return true;
        }

        $this->mirror->mirrorInbound($participant, $text, $mid ? (string) $mid : null, $event);

        // Conversational follow-gate loop (Meta-safe), now with REAL follow
        // verification via the Graph User Profile API (is_user_follow_business):
        //   verified + keyword → delivery · verified + "yes" → keyword reminder
        //   verified + other   → bounded nudge · verified NOT following → re-ask
        // Every non-delivery branch draws from the bounded nudge budget, so the
        // loop can never be driven forever; inconclusive checks FAIL OPEN.
        // Gated automations without an explicit keyword tell the user "reply DONE"
        // in the private reply — the matcher must expect exactly that default.
        // Treating an empty keyword as match-anything made ANY reply ("hello",
        // "thanks") trigger the delivery, contradicting the instructions sent.
        $keyword = trim((string) ($automation?->reply_keyword ?? '')) ?: 'DONE';
        $saidNo = KeywordMatcher::matches(['no', 'nope', 'nahi', 'nahin', 'nai', 'not yet', 'abhi nahi'], 'exact', $text);
        $saidYes = ! $saidNo && KeywordMatcher::matches(['yes', 'yess', 'haan', 'han', 'ho gaya', 'done'], 'exact', $text);


        $keywordMatched = KeywordMatcher::matches([$keyword], 'contains', $text);

        // Real follow verification (User Profile API, is_user_follow_business).
        // Meta exposes this field only AFTER the user messaged the account — the
        // DM reply that reaches this point IS that consent event. Any
        // inconclusive outcome (no consent yet, Graph/network error, missing
        // field) reads as null and the funnel FAILS OPEN: an unknown never
        // blocks a real lead.
        $followCheckEnabled = (bool) config('instagram.follow_check', true);
        $follows = $followCheckEnabled
            ? $this->client->doesUserFollow($account, $senderId)
            : null;
        $verifiedFollower = $follows !== false;

        if ($verifiedFollower && $keywordMatched) {
            $delivery = (array) ($automation?->delivery ?? []);
            InstagramLog::dm('info', 'reply received — triggering delivery', ['participant_id' => $participant->id, 'text' => mb_substr($text, 0, 120), 'keyword_matched' => $keywordMatched, 'follow_verified' => $follows, 'delivery_type' => $delivery['type'] ?? 'text']);
            $this->deliveries->deliver($participant, $delivery);

            return true;
        }

        if ($verifiedFollower && $saidYes) {
            // Follow VERIFIED via the API; they just skipped the keyword.
            $nudge = 'Amazing! 🎉 Just reply '.$keyword.' to confirm and I\'ll send it over right away!';
            InstagramLog::dm('info', 'gate: user said YES — follow verified, reminding the keyword', ['participant_id' => $participant->id, 'received_text' => mb_substr($text, 0, 120)]);
            $this->sendFollowUp($account, $participant, $automation, $senderId, $nudge, $event);

            return true;
        }

        if ($follows === false) {
            // The API VERIFIED they do not follow — they cannot pass the gate by
            // saying "yes" or "DONE". Repeat the ask (bounded by the same nudge
            // budget) so the loop can never be driven forever.
            $maxNudges = max(0, (int) config('instagram.max_nudges', 2));
            if ($participant->nudge_count < $maxNudges) {
                $participant->increment('nudge_count');
                $nudge = 'It seems you are not following us yet! Follow our account, then reply '.$keyword.' and I\'ll send it right over! 😊';
                InstagramLog::dm('info', 'gate: follow NOT verified by API — repeating the ask', ['participant_id' => $participant->id, 'nudge_count' => $participant->nudge_count]);
                $this->sendFollowUp($account, $participant, $automation, $senderId, $nudge, $event);

                return true;
            }

            // Budget exhausted — hand the stalled conversation to a human. The
            // Inbox thread already holds the full history (mirrored above), so
            // an agent can pick it up; Meta keeps a fresh 24h send window open.
            $participant->forceFill(['stage' => FunnelParticipant::STAGE_CLOSED, 'closed_at' => now()])->save();
            $this->log($account, $automation, $participant->comment_id, CommentAutomationLog::ACTION_SKIPPED, $event, $participant, 'gate loop budget exhausted (follow not verified)');
            InstagramLog::dm('info', 'gate: nudge budget exhausted without a verified follow — closed for agent handoff', ['participant_id' => $participant->id]);

            return true;
        }

        // Any other reply (question, "haha", gibberish) → bounded keyword nudge.
        $maxNudges = max(0, (int) config('instagram.max_nudges', 2));
        if ($participant->nudge_count < $maxNudges) {
            $participant->increment('nudge_count');
            $nudge = "Just reply {$keyword} and I'll send it right over! 😊";
            InstagramLog::dm('info', 'reply did not match keyword — sending nudge', ['participant_id' => $participant->id, 'nudge_count' => $participant->nudge_count, 'expected_keyword' => $keyword, 'received_text' => mb_substr($text, 0, 120)]);
            $this->sendFollowUp($account, $participant, $automation, $senderId, $nudge, $event);

            return true;
        }

        $this->log($account, $automation, $participant->comment_id, CommentAutomationLog::ACTION_SKIPPED, $event, $participant, 'nudge limit reached; keyword not matched');

        return true;
    }

    /**
     * Send one gated follow-up DM, mirror it into the Inbox thread and write the
     * audit log. Transient Graph failures are logged, never thrown — a failed
     * follow-up must not crash the webhook pipeline or close the funnel.
     */
    private function sendFollowUp(
        InstagramAccount $account,
        FunnelParticipant $participant,
        CommentAutomation $automation,
        string $toIgsid,
        string $text,
        array $event,
    ): void {
        try {
            $this->client->sendMessage($account, $toIgsid, $text, $this->followGateQuickReplies($automation));
            $this->mirror->mirrorOutbound($participant, $text);
            $this->log($account, $automation, $participant->comment_id, CommentAutomationLog::ACTION_NUDGED, $event, $participant);
        } catch (\Throwable $e) {
            $this->log($account, $automation, $participant->comment_id, CommentAutomationLog::ACTION_SKIPPED, $event, $participant, 'follow-up failed: '.$e->getMessage());
        }
    }

    /**
     * Tappable YES/NO quick replies for gated automations (Instagram Login
     * supports quick replies on graph.instagram.com). The payload mirrors what
     * the user would type, so messaging_postbacks are handled by the same
     * keyword matcher. Ungated automations send plain text.
     *
     * @return array<int, array{content_type: string, title: string, payload: string}>
     */
    private function followGateQuickReplies(CommentAutomation $automation): array
    {
        if (! $automation->follow_gate) {
            return [];
        }

        $keyword = trim((string) ($automation->reply_keyword ?: 'DONE'));

        return [
            ['content_type' => 'text', 'title' => '✅ I followed', 'payload' => $keyword],
            ['content_type' => 'text', 'title' => 'Not yet', 'payload' => 'no'],
        ];
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
            // Same default the reply matcher uses — an empty stored keyword must
            // not render as "reply with  and I'll send it over!".
            $keyword = trim((string) ($automation->reply_keyword ?? '')) ?: 'DONE';
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

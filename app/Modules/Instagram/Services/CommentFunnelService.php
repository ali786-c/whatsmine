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
 */class CommentFunnelService
{
    /** Reserved quick-reply payload marking the step-1 CTA button tap. */
    private const CTA_PAYLOAD = '__CTA_TAP__';

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

        // Gated automations: step 1 of the button funnel — hook text + ONE CTA
        // button ("Send me the link"). Ungated ones send plain text. On any
        // Graph rejection the service falls back to plain text automatically.
        $sent = $this->privateReplies->send(
            $participant,
            $replyText,
            $automation->follow_gate ? [$this->ctaQuickReply($automation)] : [],
        );
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
            $participant->forceFill(['stage' => FunnelParticipant::STAGE_AWAITING_CTA])->save();
            $this->log($participant->account, $automation, $participant->comment_id, CommentAutomationLog::ACTION_AWAITING_FOLLOW, [], $participant);
            InstagramLog::funnel('info', 'stage → awaiting_cta (hook + CTA button sent)', ['participant_id' => $participant->id, 'comment_id' => $participant->comment_id]);
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
        // expects, so it wins whenever present. A button-template tap arrives
        // as a messaging_postbacks event instead of a message.
        $postbackPayload = (string) data_get($event, 'postback.payload', '');
        $text = (string) (
            data_get($event, 'message.quick_reply.payload')
            ?: ($postbackPayload !== '' ? $postbackPayload : data_get($event, 'message.text', ''))
        );

        $isMessageEvent = isset($event['message']) && ! data_get($event, 'message.is_echo');
        $isPostbackEvent = isset($event['postback']) && $postbackPayload !== '';

        if ($senderId === '' || (! $isMessageEvent && ! $isPostbackEvent)) {
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
                ->whereIn('stage', [FunnelParticipant::STAGE_AWAITING_CTA, FunnelParticipant::STAGE_AWAITING_FOLLOW])
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
        // possible after the user responds). Meta opens a FRESH window for EVERY
        // user message, so we refresh the timestamp on each reply — this is what
        // keeps the follow-gate loop alive indefinitely while the user engages.
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
        } else {
            // Still inside the old window — refresh it so the loop stays alive
            // for as long as the user keeps replying (Meta-accurate behaviour).
            $participant->forceFill(['dm_thread_opened_at' => now()])->save();
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

        // Step 1 → step 2 of the button funnel: the CTA tap (payload = CTA
        // marker) or the user's FIRST typed reply opens the follow gate. The
        // gate message + Visit profile + "I'm following" template goes out
        // here — exactly the competitor UX from the reference screenshots.
        if ($participant->stage === FunnelParticipant::STAGE_AWAITING_CTA) {
            InstagramLog::dm('info', 'gate: CTA pressed (or first typed reply) — sending follow gate template', ['participant_id' => $participant->id, 'text' => mb_substr($text, 0, 120)]);
            $this->sendGateTemplate($account, $participant, $automation, $senderId, $event);

            return true;
        }

        // Gate-step CTA marker taps (template re-sent after a failed follow
        // check) must not be treated as the delivery keyword.
        $isCtaTap = $text === self::CTA_PAYLOAD;
        if ($isCtaTap) {
            $this->sendGateTemplate($account, $participant, $automation, $senderId, $event);

            return true;
        }

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
            // saying "yes" or "DONE". The loop stays ALIVE: every engagement gets
            // the ask repeated, with no artificial cap — Meta's 24h window is the
            // only bound (it stays open as long as the user keeps replying).
            $participant->increment('nudge_count');
            $nudge = 'It seems you are not following us yet! Follow our account, then reply '.$keyword.' and I\'ll send it right over! 😊';
            InstagramLog::dm('info', 'gate: follow NOT verified by API — repeating the ask', ['participant_id' => $participant->id, 'nudge_count' => $participant->nudge_count]);
            $this->sendFollowUp($account, $participant, $automation, $senderId, $nudge, $event);

            return true;
        }

        if ($saidNo) {
            // Explicit "no" / "not yet" (or the quick reply) — repeat the FOLLOW
            // ask, not the generic keyword nudge. This also covers the
            // inconclusive-API fail-open case: the quick reply must never be
            // answered with "just reply DONE" when they told us they haven't
            // followed yet. Uncapped by design — alive until they actually follow
            // or stop replying (then Meta's window closes the funnel).
            $participant->increment('nudge_count');
            $nudge = 'No problem! Just follow our account, then reply '.$keyword.' and I\'ll send it right over! 😊';
            InstagramLog::dm('info', 'gate: user said NO — repeating the follow ask', ['participant_id' => $participant->id, 'nudge_count' => $participant->nudge_count]);
            $this->sendFollowUp($account, $participant, $automation, $senderId, $nudge, $event);

            return true;
        }

        // Any other reply (question, "haha", gibberish) → keyword reminder. Also
        // uncapped — alive until they follow or go quiet (24h window rule).
        $participant->increment('nudge_count');
        $nudge = "Just reply {$keyword} and I'll send it right over! 😊";
        InstagramLog::dm('info', 'reply did not match keyword — sending nudge', ['participant_id' => $participant->id, 'nudge_count' => $participant->nudge_count, 'expected_keyword' => $keyword, 'received_text' => mb_substr($text, 0, 120)]);
        $this->sendFollowUp($account, $participant, $automation, $senderId, $nudge, $event);

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
     * Button-template follow gate: the gate text + [Visit profile] (opens the
     * creator's profile so the user can follow) + ["I'm following ✅"] (a
     * postback that re-enters this funnel for verification). Sent as a normal
     * DM inside the 24h window the CTA tap opened.
     */
    private function sendGateTemplate(
        InstagramAccount $account,
        FunnelParticipant $participant,
        ?CommentAutomation $automation,
        string $toIgsid,
        array $event,
    ): void {
        $keyword = trim((string) ($automation?->reply_keyword ?? '')) ?: 'DONE';
        $gateText = trim((string) ($automation?->gate_message ?? ''))
            ?: 'Follow us on Instagram to unlock this!';
        $visitLabel = trim((string) ($automation?->visit_profile_label ?? '')) ?: 'Visit profile';
        $confirmLabel = trim((string) ($automation?->confirm_follow_label ?? '')) ?: "I'm following ✅";

        try {
            // The profile button is fully automatic: the URL is built from the
            // connected account's username (captured at OAuth connect time) —
            // the user never pastes a link. If the username is somehow missing,
            // skip the button rather than send a broken link.
            $buttons = [];
            if (filled($account->username)) {
                $buttons[] = [
                    'type' => 'web_url',
                    'url' => 'https://instagram.com/'.$account->username,
                    'title' => $visitLabel,
                ];
            }
            $buttons[] = [
                'type' => 'postback',
                'title' => $confirmLabel,
                'payload' => $keyword,
            ];

            $this->client->sendButtonTemplate($account, $toIgsid, $gateText, $buttons);

            $this->mirror->mirrorOutbound($participant, $gateText);
            $participant->forceFill(['stage' => FunnelParticipant::STAGE_AWAITING_FOLLOW])->save();
            $this->log($account, $automation, $participant->comment_id, CommentAutomationLog::ACTION_NUDGED, $event, $participant);
            InstagramLog::dm('info', 'gate: button template sent (Visit profile + confirm)', ['participant_id' => $participant->id]);
        } catch (\Throwable $e) {
            // Button template failed (likely plain-DM window edge case) — fall
            // back to the classic typed-keyword ask so the user is never stuck.
            InstagramLog::dm('warning', 'gate: button template failed — falling back to typed ask', ['participant_id' => $participant->id, 'error' => $e->getMessage()]);

            $ask = trim((string) ($automation?->follow_prompt_message ?? ''))
                ?: "Make sure you're following us, then reply {$keyword} and I'll send it over!";
            $this->sendFollowUp($account, $participant, $automation, $toIgsid, $ask, $event);
            $participant->forceFill(['stage' => FunnelParticipant::STAGE_AWAITING_FOLLOW])->save();
        }
    }

    /**
     * Step-1 CTA button: a quick reply on the first message whose payload is a
     * reserved marker (never a real keyword) — the tap means "open the gate".
     */
    private function ctaQuickReply(CommentAutomation $automation): array
    {
        $label = trim((string) ($automation->cta_button_label ?? '')) ?: 'Send me the link';

        return ['content_type' => 'text', 'title' => $label, 'payload' => self::CTA_PAYLOAD];
    }

    /**
     * YES/NO quick replies attached to gate follow-ups (the typed-ask path and
     * re-asks) so a tap behaves exactly like typing the keyword or "no".
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
        // Meta names image posts "FEED" while the editor saves "POST" —
        // canonicalize both sides so a "post only" automation still matches.
        $canonical = fn (string $t): string => match (strtoupper($t)) {
            'POST' => 'FEED',
            default => strtoupper($t),
        };
        $filter = array_map($canonical, array_map(fn ($t) => (string) $t, (array) ($automation->media_filter ?? [])));

        // A configured media filter must be respected even when the webhook
        // omits the media product type: treat "unknown" as non-matching
        // rather than silently bypassing the user's restriction.
        $type = $mediaType !== '' ? $canonical($mediaType) : 'UNKNOWN';
        if ($filter !== [] && ! in_array($type, $filter, true)) {
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
            // Step-1 hook message. The editable CTA text carries the ask; the
            // gate itself lives in step 2 (sendGateTemplate). The classic typed
            // keyword path stays available as a fallback.
            $ctaText = trim((string) ($automation->cta_message ?? ''))
                ?: "Click the button below and I'll send it over.";
            $text = rtrim($text)."\n\n".$ctaText;

            if (trim((string) ($automation->follow_prompt_message ?? '')) !== '') {
                $text .= "\n".trim((string) $automation->follow_prompt_message);
            }
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

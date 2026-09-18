<?php

namespace App\Modules\Instagram\Services;

use App\Modules\Instagram\Models\Flow;
use App\Modules\Instagram\Models\FlowParticipant;
use App\Modules\Instagram\Models\InstagramAccount;
use App\Modules\Instagram\Models\InstagramTemplate;

/**
 * Executes saved visual DM flows (node/edge graphs built in the playground).
 *
 * Node types & payload shapes (stored in Flow->graph.nodes[].data):
 *   trigger      {trigger: 'comment'}                        — entry point, no outgoing logic of its own
 *   send_message {kind: 'text'|'template', text?, template_id?} — sends, then continues to the next node
 *   wait_reply   {timeout_minutes?, timeout_next_node_id?}   — pauses the flow for the user's next message
 *   condition    {cond_type: 'follow_check'|'keyword', keywords?} — routes via edges handle 'yes'/'no' | 'match'/'default'
 *   end          {}                                          — completes the flow
 *
 * Edge handles: send/wait/trigger → 'next'; condition → 'yes'/'no' (follow_check)
 * or 'match'/'default' (keyword).
 *
 * Safety rails mirrored from the classic funnel: the 24h customer window is
 * opened by the participant's FIRST inbound message and every outbound send
 * asserts it; per-participant retries are bounded (max 3 node crashes); the
 * engine walks at most 25 nodes per run.
 */
class FlowEngine
{
    private const MAX_NODES_PER_RUN = 25;

    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly InstagramGraphClient $client,
        private readonly InboxMirrorService $mirror,
    ) {}

    /**
     * Comment-triggered entry: create (or reuse) the participant and start from
     * the trigger node. Returns true when a flow actually started.
     */
    public function startFromComment(InstagramAccount $account, string $igsid, ?string $username, Flow $flow, ?string $commentId, ?string $mediaId): bool
    {
        if ($flow->status !== 'active') {
            return false; // drafts never fire
        }

        $trigger = $flow->triggerNode();
        if ($trigger === null) {
            InstagramLog::flow('warning', 'flow has no trigger node — not started', ['flow_id' => $flow->id]);

            return false;
        }

        // One active flow per person per flow — a second comment must not fork a
        // second parallel conversation for the same user.
        $existing = FlowParticipant::where('flow_id', $flow->id)
            ->where('instagram_account_id', $account->id)
            ->where('commenter_igsid', $igsid)
            ->active()
            ->first();

        if ($existing) {
            InstagramLog::flow('info', 'flow already active for participant — skipping re-entry', ['flow_id' => $flow->id, 'participant_id' => $existing->id]);

            return true;
        }

        $participant = FlowParticipant::create([
            'workspace_id' => $account->workspace_id,
            'flow_id' => $flow->id,
            'instagram_account_id' => $account->id,
            'commenter_igsid' => $igsid,
            'username' => $username,
            'comment_id' => $commentId,
            'media_id' => $mediaId,
            'current_node_id' => (string) $trigger['id'],
            'status' => FlowParticipant::STATUS_ACTIVE,
            'expires_at' => now()->addDays(7),
        ]);

        InstagramLog::flow('info', 'flow started from comment', ['flow_id' => $flow->id, 'participant_id' => $participant->id, 'username' => $username]);

        $this->runFrom($participant, (string) $trigger['id']);

        return true;
    }

    /**
     * Inbound DM / postback router. Returns true when the event belonged to an
     * active flow (caller must NOT forward it to the classic funnel/inbox).
     *
     * @param  array<string, mixed>  $event  Raw webhook messaging event
     */
    public function handleInbound(InstagramAccount $account, array $event): bool
    {
        $senderId = (string) data_get($event, 'sender.id', '');
        if ($senderId === '' || data_get($event, 'message.is_echo') || ! isset($event['message']) && ! isset($event['postback'])) {
            return false;
        }

        $participant = FlowParticipant::where('instagram_account_id', $account->id)
            ->where('commenter_igsid', $senderId)
            ->active()
            ->orderByDesc('id')
            ->first();

        if (! $participant) {
            return false;
        }

        // Postback = flow-generated button tap → payload is "FLOW:auto:{text}".
        // The stripped text is what keyword conditions match against; the human
        // title is what lands on the mirrored Inbox thread.
        $postbackPayload = (string) data_get($event, 'postback.payload', '');
        $isFlowPostback = $postbackPayload !== '' && str_starts_with($postbackPayload, 'FLOW:');
        $rawText = (string) (data_get($event, 'message.quick_reply.payload') ?: data_get($event, 'message.text', ''));
        $displayText = $rawText !== '' ? $rawText : (string) data_get($event, 'postback.title', $postbackPayload);
        $matchText = $isFlowPostback
            ? substr($postbackPayload, strlen('FLOW:auto:'))
            : ($rawText !== '' ? $rawText : $postbackPayload);

        $participant->markInbound($displayText, $matchText);

        if ($participant->waiting_for !== 'reply') {
            // User messaged while the flow wasn't waiting — treat as a fresh run
            // from their current node so they never strand silently.
            InstagramLog::flow('info', 'inbound while not waiting — resuming from current node', ['participant_id' => $participant->id, 'node' => $participant->current_node_id]);
            $this->runFrom($participant, (string) $participant->current_node_id);

            return true;
        }

        $participant->forceFill(['waiting_for' => null, 'wait_started_at' => null])->save();

        $node = $this->nodeById($participant->flow, (string) $participant->current_node_id);
        $data = (array) ($node['data'] ?? []);

        // Keyword condition waiting on this reply?
        if (($data['cond_type'] ?? '') === 'keyword') {
            $matchable = (string) ($participant->context['last_match_text'] ?? $participant->context['last_inbound_text'] ?? '');
            $matched = FlowKeywordMatcher::matches((array) ($data['keywords'] ?? []), $matchable);
            $this->followEdge($participant, $node, $matched ? 'match' : 'default');

            return true;
        }

        // Plain wait_reply: route via the FIRST outgoing edge (timeout edge is
        // only taken by the scheduler). Builders typically connect wait → condition.
        $this->followEdge($participant, $node, 'next');

        return true;
    }

    /**
     * Scheduler entry: resume waits whose timeout elapsed (timeout edge) and
     * expire stale participants (7-day window).
     */
    public function resumeTimedOutWaits(): int
    {
        $resumed = 0;

        FlowParticipant::query()
            ->active()
            ->where('waiting_for', 'reply')
            ->whereNotNull('wait_started_at')
            ->where('wait_started_at', '<', now()->subMinutes(1))
            ->with('flow')
            ->chunkById(100, function ($participants) use (&$resumed): void {
                foreach ($participants as $participant) {
                    $node = $this->nodeById($participant->flow, (string) $participant->current_node_id);
                    $data = (array) ($node['data'] ?? []);
                    $timeout = max(1, (int) ($data['timeout_minutes'] ?? 0));

                    if ($timeout > 0 && $participant->wait_started_at->copy()->addMinutes($timeout)->isPast()) {
                        $participant->forceFill(['waiting_for' => null, 'wait_started_at' => null])->save();
                        $timeoutNext = (string) ($data['timeout_next_node_id'] ?? '');
                        $timeoutEdge = $this->edgeByHandle($participant->flow, (string) $node['id'], 'timeout');

                        if ($timeoutNext !== '') {
                            $this->runFrom($participant, $timeoutNext);
                        } elseif ($timeoutEdge !== null) {
                            $this->runFrom($participant, (string) $timeoutEdge['target']);
                        } else {
                            // No timeout path wired — close so the user is never stuck.
                            $participant->forceFill(['status' => FlowParticipant::STATUS_EXPIRED])->save();
                            InstagramLog::flow('info', 'wait timeout without timeout edge — participant expired', ['participant_id' => $participant->id]);
                        }

                        $resumed++;
                    }
                }
            });

        // 7-day hard expiry for abandoned flows.
        FlowParticipant::query()
            ->active()
            ->where('expires_at', '<', now())
            ->update(['status' => FlowParticipant::STATUS_EXPIRED]);

        return $resumed;
    }

    // ------------------------------------------------------------------
    // Core walk
    // ------------------------------------------------------------------

    private function runFrom(FlowParticipant $participant, string $fromNodeId): void
    {
        $flow = $participant->flow;
        $currentId = $fromNodeId;
        $steps = 0;

        while ($steps++ < self::MAX_NODES_PER_RUN) {
            $node = $this->nodeById($flow, $currentId);

            if ($node === null) {
                InstagramLog::flow('warning', 'flow node missing — completing participant', ['flow_id' => $flow->id, 'participant_id' => $participant->id, 'node' => $currentId]);
                $this->complete($participant);

                return;
            }

            $participant->forceFill(['current_node_id' => $currentId])->save();

            $type = (string) ($node['type'] ?? 'end');

            try {
                $result = match ($type) {
                    'trigger' => 'next',
                    'send_message' => $this->execSendMessage($participant, $flow, $node),
                    'wait_reply' => $this->execWaitReply($participant, $node),
                    'condition' => $this->execCondition($participant, $node),
                    'end' => null,
                    default => null,
                };
            } catch (\Throwable $e) {
                InstagramLog::flow('error', 'flow node crashed', ['flow_id' => $flow->id, 'participant_id' => $participant->id, 'node' => $currentId, 'error' => $e->getMessage()]);

                if ($participant->retry_count >= self::MAX_RETRIES) {
                    $participant->forceFill(['status' => FlowParticipant::STATUS_EXPIRED])->save();

                    return;
                }

                $participant->increment('retry_count');

                return; // re-drive later via inbound message or scheduler
            }

            // wait_reply paused the walk
            if ($result === 'wait') {
                return;
            }

            // end node (or unknown) → done
            if ($result === null) {
                $this->complete($participant);

                return;
            }

            $next = $this->nextNode($flow, $node, is_string($result) ? $result : 'next');

            if ($next === null) {
                // Nothing wired — flow ends here naturally.
                $this->complete($participant);

                return;
            }

            $currentId = $next;
        }

        // Loop guard tripped — a cycle without a wait/condition must not spin forever.
        InstagramLog::flow('warning', 'flow exceeded max nodes per run — completing', ['flow_id' => $flow->id, 'participant_id' => $participant->id]);
        $this->complete($participant);
    }

    /** @return string|null 'next' | handle string | 'wait' | null(=end) */
    private function execSendMessage(FlowParticipant $participant, Flow $flow, array $node): ?string
    {
        $data = (array) ($node['data'] ?? []);
        $account = $participant->account;

        if (! $participant->windowOpen()) {
            // Comment-triggered FIRST message is exempt: it rides the private
            // reply (which Meta permits once per comment within 7 days).
            if ($participant->comment_id === null || $participant->current_node_id !== $this->firstSendNodeId($flow)) {
                InstagramLog::flow('warning', 'send skipped — 24h window not open', ['participant_id' => $participant->id, 'node' => $node['id'] ?? '']);
                $this->complete($participant);

                return null;
            }
        }

        $kind = (string) ($data['kind'] ?? 'text');

        try {
            $res = match ($kind) {
                'template' => $this->sendTemplate($account, $participant, $data),
                default => $this->client->sendMessage($account, $participant->commenter_igsid, $this->renderVars((string) ($data['text'] ?? ''), $participant)),
            };
        } catch (\Throwable $e) {
            InstagramLog::flow('error', 'flow send failed', ['participant_id' => $participant->id, 'node' => $node['id'] ?? '', 'error' => $e->getMessage()]);
            throw $e;
        }

        $body = $kind === 'template'
            ? '[template] '.(string) ($data['template_name'] ?? $data['template_id'] ?? '')
            : (string) ($data['text'] ?? '');

        $this->mirror->mirrorOutbound($participant, $this->renderVars($body, $participant), (string) ($res['message_id'] ?? ''), $res);

        InstagramLog::flow('info', 'flow message sent', ['participant_id' => $participant->id, 'node' => $node['id'] ?? '', 'kind' => $kind]);

        return 'next';
    }

    private function sendTemplate(InstagramAccount $account, FlowParticipant $participant, array $data): array
    {
        // Saved library template (pick in builder) takes precedence.
        $templateId = (int) ($data['template_id'] ?? 0);

        if ($templateId > 0) {
            $template = InstagramTemplate::forWorkspace((int) $participant->workspace_id)->find($templateId);

            if ($template) {
                return $this->sendTemplateDefinition($account, $participant, (array) $template->definition);
            }
        }

        // Inline definition authored directly in the builder.
        return $this->sendTemplateDefinition($account, $participant, (array) ($data['definition'] ?? []));
    }

    private function sendTemplateDefinition(InstagramAccount $account, FlowParticipant $participant, array $definition): array
    {
        $type = (string) ($definition['type'] ?? 'button');

        if ($type === 'generic') {
            // Auto-route postback buttons back into this flow.
            $elements = array_map(function (array $el): array {
                if (! empty($el['buttons'])) {
                    $el['buttons'] = array_map(function (array $b): array {
                        if (($b['type'] ?? 'web_url') === 'postback' && ! str_starts_with((string) ($b['payload'] ?? ''), 'FLOW:')) {
                            $b['payload'] = 'FLOW:auto:'.$b['payload'];
                        }

                        return $b;
                    }, $el['buttons']);
                }

                return $el;
            }, (array) ($definition['elements'] ?? []));

            return $this->client->sendGenericTemplate($account, $participant->commenter_igsid, $elements);
        }

        $buttons = array_map(function (array $b): array {
            if (($b['type'] ?? 'web_url') === 'postback' && ! str_starts_with((string) ($b['payload'] ?? ''), 'FLOW:')) {
                $b['payload'] = 'FLOW:auto:'.$b['payload'];
            }

            return $b;
        }, (array) ($definition['buttons'] ?? []));

        return $this->client->sendButtonTemplate($account, $participant->commenter_igsid, (string) ($definition['text'] ?? ''), $buttons);
    }

    /** @return string|null 'wait' | handle | null */
    private function execWaitReply(FlowParticipant $participant, array $node): ?string
    {
        $data = (array) ($node['data'] ?? []);
        $timeout = max(1, (int) ($data['timeout_minutes'] ?? 0));

        $participant->forceFill([
            'waiting_for' => 'reply',
            'wait_started_at' => now(),
        ])->save();

        InstagramLog::flow('info', 'flow waiting for reply', ['participant_id' => $participant->id, 'node' => $node['id'] ?? '', 'timeout_minutes' => $timeout]);

        return 'wait';
    }

    private function execCondition(FlowParticipant $participant, array $node): ?string
    {
        $data = (array) ($node['data'] ?? []);
        $condType = (string) ($data['cond_type'] ?? 'follow_check');

        if ($condType === 'keyword') {
            $text = (string) ($participant->context['last_match_text'] ?? $participant->context['last_inbound_text'] ?? '');
            $matched = $text !== '' && FlowKeywordMatcher::matches((array) ($data['keywords'] ?? []), $text);
            InstagramLog::flow('info', 'condition keyword evaluated', ['participant_id' => $participant->id, 'text' => mb_substr($text, 0, 60), 'matched' => $matched]);

            return $matched ? 'match' : 'default';
        }

        // follow_check — real verification via Graph; inconclusive FAILS OPEN
        // (same policy as the classic funnel gate).
        $follows = $this->client->doesUserFollow($participant->account, $participant->commenter_igsid);
        $result = $follows === false ? 'no' : 'yes';

        InstagramLog::flow('info', 'condition follow_check evaluated', ['participant_id' => $participant->id, 'follows' => $follows === null ? 'unknown(fail-open)' : ($follows ? 'true' : 'false')]);

        return $result;
    }

    // ------------------------------------------------------------------
    // Graph helpers
    // ------------------------------------------------------------------

    private function followEdge(FlowParticipant $participant, ?array $node, string $handle): void
    {
        if ($node === null) {
            $this->complete($participant);

            return;
        }

        $next = $this->nextNode($participant->flow, $node, $handle) ?? $this->nextNode($participant->flow, $node, 'next');

        if ($next === null) {
            $this->complete($participant);

            return;
        }

        $this->runFrom($participant, $next);
    }

    private function nextNode(Flow $flow, array $node, string $handle): ?string
    {
        $edge = $this->edgeByHandle($flow, (string) $node['id'], $handle);

        return $edge === null ? null : (string) $edge['target'];
    }

    private function edgeByHandle(Flow $flow, string $sourceId, string $handle): ?array
    {
        foreach ($flow->edges() as $edge) {
            $edgeHandle = (string) ($edge['sourceHandle'] ?? 'next');
            $normalized = str_starts_with($edgeHandle, 'cond-') ? substr($edgeHandle, 5) : $edgeHandle;

            if ((string) ($edge['source'] ?? '') === $sourceId && ($normalized === $handle || $edgeHandle === $handle)) {
                return ['target' => (string) ($edge['target'] ?? ''), 'handle' => $normalized];
            }
        }

        return null;
    }

    private function nodeById(Flow $flow, string $nodeId): ?array
    {
        foreach ($flow->nodes() as $node) {
            if ((string) ($node['id'] ?? '') === $nodeId) {
                return $node;
            }
        }

        return null;
    }

    private function firstSendNodeId(Flow $flow): ?string
    {
        // First send_message reachable from the trigger via 'next' edges only.
        $trigger = $flow->triggerNode();

        if ($trigger === null) {
            return null;
        }

        $seen = [];
        $current = (string) $trigger['id'];

        while ($current !== '' && ! isset($seen[$current])) {
            $seen[$current] = true;
            $node = $this->nodeById($flow, $current);

            if ($node === null) {
                return null;
            }

            if (($node['type'] ?? '') === 'send_message') {
                return (string) $node['id'];
            }

            $edge = $this->edgeByHandle($flow, $current, 'next');
            $current = $edge === null ? '' : (string) $edge['target'];
        }

        return null;
    }

    private function complete(FlowParticipant $participant): void
    {
        $participant->forceFill([
            'status' => FlowParticipant::STATUS_COMPLETED,
            'waiting_for' => null,
            'wait_started_at' => null,
        ])->save();

        InstagramLog::flow('info', 'flow completed', ['flow_id' => $participant->flow_id, 'participant_id' => $participant->id]);
    }

    /** Replace {username} / {post_url} vars, mirroring the classic funnel render. */
    private function renderVars(string $text, FlowParticipant $participant): string
    {
        return str_replace(
            ['{username}', '{post_url}'],
            [$participant->username ?: 'friend', ''],
            $text,
        );
    }
}

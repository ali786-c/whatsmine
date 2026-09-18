<?php

namespace App\Modules\Instagram\Services;

use App\Modules\Instagram\Exceptions\InstagramGraphException;
use App\Modules\Instagram\Models\CommentAutomationLog;
use App\Modules\Instagram\Models\Flow;
use App\Modules\Instagram\Models\FunnelParticipant;

/**
 * Builds and sends the "required thing" (lead delivery): text, link or file.
 *
 * Meta's constraint — ONE message per comment — is respected by embedding the
 * delivery into the private reply when the reply hasn't been sent yet
 * (no-reply mode, used when follow_gate is off). With follow_gate on, the
 * participant replies first (opening the 24h window), and the delivery goes as
 * a follow-up send in that window.
 */
class LeadDeliveryService
{
    public function __construct(
        private readonly InstagramGraphClient $client,
        private readonly InboxMirrorService $mirror,
    ) {}

    /**
     * No-reply mode: append the delivery to the private reply text BEFORE it is
     * sent (used when follow_gate is off — there is no follow-up opportunity).
     *
     * @param  array<string, mixed>  $delivery
     */
    public function renderForPrivateReply(array $delivery): string
    {
        $block = trim((string) ($this->renderBlock($delivery) ?? ''));

        return $block === '' ? '' : "\n\n".$block;
    }

    /**
     * Follow-up mode: send the delivery as a DM inside the 24h window opened by
     * the participant's reply.
     *
     * @param  array<string, mixed>  $delivery
     * @return bool true when the delivery went out
     */
    public function deliver(FunnelParticipant $participant, array $delivery): bool
    {
        $log = fn (string $action, array $extra = []) => CommentAutomationLog::create(array_merge([
            'workspace_id' => $participant->workspace_id,
            'instagram_account_id' => $participant->instagram_account_id,
            'automation_id' => $participant->automation_id,
            'funnel_participant_id' => $participant->id,
            'comment_id' => $participant->comment_id,
            'stage' => $participant->stage,
        ], $extra));

        $type = (string) ($delivery['type'] ?? 'text');

        // Visual DM flow delivery: hand the conversation to the FlowEngine and
        // mark the classic funnel delivered — the flow now owns this thread.
        if ($type === 'flow' && filled($delivery['flow_id'] ?? null)) {
            $flow = Flow::find((int) $delivery['flow_id']);

            if ($flow && $flow->triggerNode() !== null) {
                $started = app(FlowEngine::class)->startFromComment(
                    $participant->account,
                    $participant->commenter_igsid,
                    $participant->username,
                    $flow,
                    $participant->comment_id,
                    $participant->media_id,
                );

                if ($started) {
                    $participant->forceFill([
                        'stage' => FunnelParticipant::STAGE_DELIVERED,
                        'delivered_at' => now(),
                    ])->save();
                    $log(CommentAutomationLog::ACTION_DELIVERED, ['response_json' => ['handed_to_flow' => $flow->id]]);

                    return true;
                }
            }

            $log(CommentAutomationLog::ACTION_DELIVERY_FAILED, ['error' => 'flow delivery: flow missing, draft or without trigger node']);

            return false;
        }

        InstagramLog::delivery('info', 'delivering lead follow-up', [
            'participant_id' => $participant->id,
            'comment_id' => $participant->comment_id,
            'type' => $type,
            'url' => $delivery['url'] ?? null,
            'filename' => $delivery['filename'] ?? null,
        ]);

        try {
            $messageId = null;
            $res = null;

            if ($type === 'file' && filled($delivery['url'] ?? null)) {
                $mime = strtolower((string) pathinfo((string) $delivery['url'], PATHINFO_EXTENSION));
                $attachmentType = in_array($mime, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) ? 'image' : 'file';
                $res = $this->client->sendAttachment(
                    $participant->account,
                    $participant->commenter_igsid,
                    $attachmentType,
                    (string) $delivery['url'],
                );
                $messageId = $res['message_id'] ?? null;
            } elseif ($type === 'link' && filled($delivery['url'] ?? null)) {
                $text = trim((string) ($delivery['text'] ?? ''))."\n".(string) $delivery['url'];
                $res = $this->client->sendMessage($participant->account, $participant->commenter_igsid, $text);
                $messageId = $res['message_id'] ?? null;
            } else {
                $text = trim((string) ($delivery['text'] ?? ''));
                if ($text === '') {
                    $log(CommentAutomationLog::ACTION_SKIPPED, ['error' => 'empty delivery payload']);

                    return false;
                }
                $res = $this->client->sendMessage($participant->account, $participant->commenter_igsid, $text);
                $messageId = $res['message_id'] ?? null;
            }

            $participant->forceFill([
                'stage' => FunnelParticipant::STAGE_DELIVERED,
                'delivered_at' => now(),
            ])->save();

            $log(CommentAutomationLog::ACTION_DELIVERED, ['response_json' => $res]);
            InstagramLog::delivery('info', 'lead DELIVERED', ['participant_id' => $participant->id, 'comment_id' => $participant->comment_id, 'type' => $type, 'message_id' => $messageId]);

            // Mirror the delivered content locally for the Inbox thread.
            $this->mirror->mirrorOutbound(
                $participant,
                $this->describeDelivery($delivery),
                $messageId,
                $delivery,
            );

            return true;
        } catch (InstagramGraphException $e) {
            if ($e->isTokenPermissionError()) {
                $participant->account->markTokenExpired();
            }

            $log(CommentAutomationLog::ACTION_DELIVERY_FAILED, ['error' => $e->getMessage()]);

            InstagramLog::delivery('error', 'lead delivery FAILED', [
                'participant_id' => $participant->id,
                'comment_id' => $participant->comment_id,
                'type' => $type,
                'code' => $e->graphErrorCode,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $delivery
     */
    private function renderBlock(array $delivery): ?string
    {
        $type = (string) ($delivery['type'] ?? 'text');

        if ($type === 'link' && filled($delivery['url'] ?? null)) {
            return trim((string) ($delivery['text'] ?? ''))."\n".(string) $delivery['url'];
        }

        if ($type === 'file' && filled($delivery['url'] ?? null)) {
            $name = trim((string) ($delivery['filename'] ?? ''));
            $prefix = $name !== '' ? $name.' ' : '';
            $body = trim((string) ($delivery['text'] ?? ''));

            return trim($body."\n".$prefix.(string) $delivery['url']);
        }

        $text = trim((string) ($delivery['text'] ?? ''));

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $delivery
     */
    private function describeDelivery(array $delivery): string
    {
        $type = (string) ($delivery['type'] ?? 'text');

        if ($type === 'file') {
            return trim((string) ($delivery['text'] ?? '')).' [file: '.(string) ($delivery['filename'] ?? $delivery['url'] ?? '').']';
        }

        if ($type === 'link') {
            return trim((string) ($delivery['text'] ?? '')).' '.(string) ($delivery['url'] ?? '');
        }

        return (string) ($delivery['text'] ?? '');
    }
}

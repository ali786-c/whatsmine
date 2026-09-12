<?php

namespace App\Modules\Instagram\Services;

use App\Events\ContactCreated;
use App\Events\MessageReceived;
use App\Modules\Instagram\Models\FunnelParticipant;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Support\Facades\Log;

/**
 * Optional, one-directional bridge into the shared Inbox: funnel DM threads are
 * mirrored as Contact/Conversation/Message rows so human agents can take over
 * mid-funnel. Keyed exactly like the Inbox InstagramDriver (IGSID in
 * custom_fields.instagram_psid, external_thread_id = IGSID) so both integrations
 * land on the SAME contact and thread.
 *
 * Mirroring is best-effort by design: any failure is logged and swallowed — the
 * funnel itself never depends on the Inbox, and the Inbox never depends on this
 * module. Disable with INSTAGRAM_MIRROR_TO_INBOX=false.
 */
class InboxMirrorService
{
    public function enabled(): bool
    {
        return (bool) config('instagram.mirror_to_inbox', true);
    }

    /**
     * Record an outbound funnel message (private reply / delivery / nudge) on the
     * mirrored thread.
     */
    public function mirrorOutbound(FunnelParticipant $participant, string $body, ?string $providerMessageId = null, array $payload = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            [$contact, $conversation] = $this->resolveThread($participant);

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'channel' => 'instagram',
                'type' => 'text',
                'payload' => $payload ?: null,
                'body' => $body,
                'status' => 'delivered',
                'provider_message_id' => $providerMessageId,
                'sent_by' => 'automation',
                'sent_at' => now(),
            ]);

            $conversation->update(['last_message_at' => now()]);

            Log::info('instagram_module: mirrored outbound to inbox', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
            ]);
        } catch (\Throwable $e) {
            $this->logMirrorFailure('outbound', $participant, $e);
        }
    }

    /**
     * Record an inbound DM reply from the participant and dispatch
     * MessageReceived so existing Inbox automations/chatbots can react.
     */
    public function mirrorInbound(FunnelParticipant $participant, string $body, ?string $mid = null, array $payload = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            [$contact, $conversation] = $this->resolveThread($participant);

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'in',
                'channel' => 'instagram',
                'type' => 'text',
                'payload' => $payload ?: null,
                'body' => $body,
                'status' => 'delivered',
                'provider_message_id' => $mid,
                'sent_by' => 'human',
                'sent_at' => now(),
            ]);

            $conversation->update([
                'last_message_at' => now(),
                'last_inbound_at' => now(),
                'status' => 'open',
                'unread_count' => $conversation->unread_count + 1,
            ]);

            MessageReceived::dispatch($message);

            Log::info('instagram_module: mirrored inbound to inbox', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
            ]);
        } catch (\Throwable $e) {
            $this->logMirrorFailure('inbound', $participant, $e);
        }
    }

    /**
     * @return array{0: Contact, 1: Conversation}
     */
    private function resolveThread(FunnelParticipant $participant): array
    {
        $workspaceId = (int) $participant->workspace_id;
        $igsid = $participant->commenter_igsid;

        // Prefer the Inbox module's instagram ChannelAccount for the same IG
        // account so agent replies sent from the Inbox reach the same IGSID
        // using the same page token. Fall back to a channel-less conversation.
        $channelAccountId = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'instagram')
            ->where(function ($q) use ($igsid, $participant) {
                $q->whereJsonContains('meta_json->instagram_page_id', $participant->account->ig_user_id)
                    ->orWhereJsonContains('meta_json->instagram_account_id', $participant->account->ig_user_id);
            })
            ->value('id');

        $contact = Contact::where('workspace_id', $workspaceId)
            ->whereJsonContains('custom_fields->instagram_psid', $igsid)
            ->first();

        if (! $contact) {
            $contact = Contact::create([
                'workspace_id' => $workspaceId,
                'source' => 'instagram',
                'first_name' => $participant->username,
                'custom_fields' => array_filter([
                    'instagram_psid' => $igsid,
                    'instagram_username' => $participant->username,
                ]),
            ]);

            ContactCreated::dispatch($contact);
        }

        $conversation = Conversation::firstOrCreate(
            [
                'workspace_id' => $workspaceId,
                'contact_id' => $contact->id,
                'channel_account_id' => $channelAccountId,
            ],
            [
                'status' => 'open',
                'external_thread_id' => $igsid,
                'assigned_to' => 'bot',
            ],
        );

        return [$contact, $conversation];
    }

    private function logMirrorFailure(string $direction, FunnelParticipant $participant, \Throwable $e): void
    {
        Log::warning('instagram_module: inbox mirroring failed (funnel unaffected)', [
            'direction' => $direction,
            'participant_id' => $participant->id,
            'error' => $e->getMessage(),
        ]);
    }
}

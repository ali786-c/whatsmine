<?php

namespace App\Modules\Inbox\Services;

use App\Events\ContactCreated;
use App\Events\MessageReceived;
use App\Events\MessageSent;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ContactService;
use App\Services\WebhookIdempotencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MessengerDriver implements ChannelDriverInterface
{
    private const BASE = 'https://graph.facebook.com/v20.0';

    public function __construct(private ContactService $contactService) {}

    public function send(Message $message): string
    {
        $conv = $message->conversation;
        $channelAcct = $conv->channelAccount;
        $creds = $channelAcct?->credentials ?? [];
        $accessToken = $creds['page_access_token'] ?? '';

        if (! $accessToken) {
            throw new \RuntimeException('Messenger page access token not configured.');
        }

        $recipient = ['id' => $conv->external_thread_id];
        $payload = $message->payload ?? [];
        $imageUrl = $payload['link'] ?? $payload['preview_url'] ?? null;

        // Image messages (e.g. shared products): send the photo as an attachment,
        // then the caption as a follow-up — a Messenger attachment carries no text.
        if ($message->type === 'image' && $imageUrl) {
            $messageId = $this->postMessage($accessToken, $recipient, [
                'attachment' => ['type' => 'image', 'payload' => ['url' => $imageUrl, 'is_reusable' => true]],
            ]);
            if (! empty($message->body)) {
                $this->postMessage($accessToken, $recipient, ['text' => $message->body]);
            }

            return $messageId;
        }

        return $this->postMessage($accessToken, $recipient, ['text' => $message->body]);
    }

    /**
     * POST a single message object to the Send API and return the message id.
     *
     * @param  array<string, mixed>  $recipient
     * @param  array<string, mixed>  $message
     */
    private function postMessage(string $accessToken, array $recipient, array $message): string
    {
        $resp = Http::withToken($accessToken)
            ->timeout(15)
            ->post(self::BASE.'/me/messages', [
                'recipient' => $recipient,
                'message' => $message,
                'messaging_type' => 'RESPONSE',
            ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('Messenger send failed: '.$resp->body());
        }

        return $resp->json('message_id', '');
    }

    public function receiveWebhook(Request $request): array
    {
        return $this->processWebhookPayload($request->all());
    }

    public function processWebhookPayload(array $payload): array
    {
        $idempotency = app(WebhookIdempotencyService::class);
        $processed = [];

        $entries = $payload['entry'] ?? [];
        Log::info('Messenger webhook: processing payload', [
            'entry_count' => count($entries),
            'entry_ids' => collect($entries)->pluck('id')->filter()->values()->all(),
        ]);

        foreach ($entries as $entry) {
            $entryId = $entry['id'] ?? '';
            $events = $entry['messaging'] ?? [];

            Log::info('Messenger webhook: entry', [
                'entry_id' => $entryId,
                'messaging_count' => count($events),
            ]);

            foreach ($events as $event) {
                try {
                    if (! isset($event['message'])) {
                        Log::info('Messenger webhook: non-message event skipped', [
                            'entry_id' => $entryId,
                            'keys' => array_keys($event),
                        ]);

                        continue;
                    }

                    if ($event['message']['is_echo'] ?? false) {
                        // Echoes fired for messages OUR app sent are duplicates
                        // of rows already persisted at send time. Echoes from
                        // the Page Inbox, the mobile Messenger app or another
                        // tool (different/absent app_id) are the ONLY way those
                        // replies ever reach this inbox — sync them as outbound
                        // messages so every surface stays in one thread.
                        $echo = $this->processEchoEvent($entryId, $event);

                        if ($echo !== null) {
                            $processed[] = $echo;
                        }

                        continue;
                    }

                    $mid = $event['message']['mid'] ?? null;
                    if ($mid && ! $idempotency->isNewEvent('messenger', $mid)) {
                        Log::info('Messenger webhook: duplicate message skipped', ['mid' => $mid]);

                        continue;
                    }

                    $message = $this->processInboundMessage($entryId, $event);

                    if ($message !== null) {
                        $processed[] = $message;
                    }
                } catch (\Throwable $e) {
                    Log::error('Messenger webhook failed', [
                        'entry_id' => $entryId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('Messenger webhook: done', ['processed_count' => count($processed)]);

        return $processed;
    }

    public function verifyCreds(): bool
    {
        return true;
    }

    /**
     * Persist an echo (outbound) webhook event as an outbound message.
     *
     * Echoes from OUR app (matching app_id) are skipped — the send flow already
     * wrote the row, and writing it again would duplicate the bubble. Echoes
     * from any other surface (Page Inbox, mobile Messenger app, third-party
     * tools — absent or different app_id) become direction=out rows so replies
     * typed on the phone stay in the same inbox thread as web replies.
     */
    private function processEchoEvent(string $pageId, array $event): ?Message
    {
        $mid = $event['message']['mid'] ?? null;

        if ($mid && ! app(WebhookIdempotencyService::class)->isNewEvent('messenger', $mid)) {
            Log::info('Messenger webhook: duplicate echo skipped', ['mid' => $mid]);

            return null;
        }

        $channelAccount = ChannelAccount::where('channel', 'messenger')
            ->whereJsonContains('meta_json->page_id', $pageId)
            ->first();

        if (! $channelAccount) {
            Log::info('Messenger webhook: echo for unconnected page — dropped', [
                'entry_id' => $pageId,
                'mid' => $mid,
            ]);

            return null;
        }

        $workspaceId = $channelAccount->workspace_id;
        $recipientId = (string) ($event['recipient']['id'] ?? '');
        $msgBody = (string) ($event['message']['text'] ?? '');
        $echoAppId = isset($event['message']['app_id']) ? (string) $event['message']['app_id'] : null;

        if ($this->echoFromOwnApp($echoAppId)) {
            Log::info('Messenger webhook: echo from our own app — skipped (already stored at send time)', [
                'entry_id' => $pageId,
                'mid' => $mid,
                'app_id' => $echoAppId,
            ]);

            return null;
        }

        // Belt-and-braces duplicate guard: the Send API response stores the
        // same mid on the row it persisted, so an echo whose mid is already on
        // ANY stored message is by definition our own send mirrored back —
        // covers pages that omit app_id on echoes.
        if ($mid && Message::where('provider_message_id', $mid)->exists()) {
            Log::info('Messenger webhook: echo mid already stored — skipped', ['mid' => $mid]);

            return null;
        }

        if ($msgBody === '' && empty($event['message']['attachments'])) {
            Log::info('Messenger webhook: echo carried no text/attachment — skipped', [
                'entry_id' => $pageId,
                'mid' => $mid,
            ]);

            return null;
        }

        $contact = Contact::where('workspace_id', $workspaceId)
            ->whereJsonContains('custom_fields->messenger_psid', $recipientId)
            ->first();

        if (! $contact) {
            // Echo for a PSID we have never seen inbound — the thread does not
            // exist on our side, so there is nothing meaningful to attach the
            // outbound reply to.
            Log::info('Messenger webhook: echo for unknown PSID — skipped', [
                'entry_id' => $pageId,
                'psid' => $recipientId,
            ]);

            return null;
        }

        $conversation = Conversation::firstOrCreate(
            ['workspace_id' => $workspaceId, 'contact_id' => $contact->id, 'channel_account_id' => $channelAccount->id],
            ['status' => 'open', 'external_thread_id' => $recipientId]
        );

        $attachment = $event['message']['attachments'][0] ?? null;
        $type = 'text';
        $payload = [];
        if (($attachment['type'] ?? null) === 'image' && ! empty($attachment['payload']['url'])) {
            $type = 'image';
            $payload = ['link' => $attachment['payload']['url']];
        }

        // Echo timestamps are in seconds; sent_at would otherwise be "now",
        // which mis-orders the bubble against the agent's earlier replies.
        $sentAt = isset($event['timestamp'])
            ? Carbon::createFromTimestampMs((int) $event['timestamp'])
            : now();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'messenger',
            'type' => $type,
            'payload' => $event,
            'body' => $msgBody,
            'status' => 'sent',
            'provider_message_id' => $mid,
            'sent_by' => 'human',
            'sent_at' => $sentAt,
        ]);

        $conversation->update(['last_message_at' => $sentAt]);

        // Real-time echo into any open inbox tabs, same as locally-sent messages.
        MessageSent::dispatch($message);

        Log::info('Messenger webhook: echo synced as outbound message', [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'workspace_id' => $workspaceId,
            'mid' => $mid,
            'echo_app_id' => $echoAppId,
        ]);

        return $message;
    }

    /**
     * Whether an echo event originated from THIS app's own Send API calls.
     * Our own echoes duplicate rows the send flow already persisted.
     */
    private function echoFromOwnApp(?string $echoAppId): bool
    {
        if ($echoAppId === null) {
            return false; // older pages / no app_id — treat as external surface
        }

        $ownAppId = CredentialResolver::system()->meta()?->appId();

        return $ownAppId !== null && $echoAppId === (string) $ownAppId;
    }

    private function processInboundMessage(string $pageId, array $event): ?Message
    {
        $senderId = $event['sender']['id'] ?? '';
        $msgBody = $event['message']['text'] ?? '';

        // The webhook entry.id is the Facebook Page id. Match it against the page_id
        // we persist when the page was connected (InboxSetupController).
        $channelAccount = ChannelAccount::where('channel', 'messenger')
            ->whereJsonContains('meta_json->page_id', $pageId)
            ->first();

        // No matching page → the message belonged to a page that isn't connected to
        // any workspace. Drop it (and log) instead of silently writing it to
        // workspace 0, where it would never appear in any real inbox.
        if (! $channelAccount) {
            Log::warning('Messenger webhook: no channel account matched — message dropped', [
                'entry_id' => $pageId,
                'sender_id' => $senderId,
                'known_ids' => ChannelAccount::where('channel', 'messenger')
                    ->pluck('meta_json')
                    ->map(fn ($m) => $m['page_id'] ?? null)
                    ->filter()->values()->all(),
            ]);

            return null;
        }

        $workspaceId = $channelAccount->workspace_id;

        Log::info('Messenger webhook: inbound message matched', [
            'entry_id' => $pageId,
            'channel_account_id' => $channelAccount->id,
            'workspace_id' => $workspaceId,
            'sender_id' => $senderId,
            'mid' => $event['message']['mid'] ?? null,
        ]);

        $contact = $this->resolveMessengerContact($workspaceId, $senderId, $channelAccount);

        $conversation = Conversation::firstOrCreate(
            ['workspace_id' => $workspaceId, 'contact_id' => $contact->id, 'channel_account_id' => $channelAccount->id],
            ['status' => 'open', 'external_thread_id' => $senderId]
        );

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'messenger',
            'type' => 'text',
            'payload' => $event,
            'body' => $msgBody,
            'status' => 'delivered',
            'provider_message_id' => $event['message']['mid'] ?? null,
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now(), 'status' => 'open', 'unread_count' => $conversation->unread_count + 1]);

        MessageReceived::dispatch($message);

        Log::info('Messenger webhook: message stored', [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'workspace_id' => $workspaceId,
        ]);

        return $message;
    }

    /**
     * Find-or-create the contact for a Messenger sender, keyed on the stable PSID
     * (stored in custom_fields.messenger_psid) so repeat messages map to ONE contact
     * instead of creating a new "Unknown" contact per message. Backfills the display
     * name and avatar from the page-scoped user profile when available.
     */
    private function resolveMessengerContact(int $workspaceId, string $psid, ChannelAccount $channelAccount): Contact
    {
        $profile = $this->fetchSenderProfile(
            $psid,
            $channelAccount->credentials['page_access_token'] ?? '',
            (string) ($channelAccount->meta_json['page_id'] ?? '')
        );

        $firstName = $profile['first_name'] ?? null;
        $lastName = $profile['last_name'] ?? null;

        $contact = Contact::where('workspace_id', $workspaceId)
            ->whereJsonContains('custom_fields->messenger_psid', $psid)
            ->first();

        if (! $contact) {
            $contact = Contact::create([
                'workspace_id' => $workspaceId,
                'source' => 'messenger',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'custom_fields' => array_filter(['messenger_psid' => $psid]),
            ]);

            ContactCreated::dispatch($contact);
        } elseif ($firstName && ! $contact->first_name) {
            // Backfill name only when we learned one and the contact doesn't already
            // have one (never clobber a manually edited contact).
            $contact->update(['first_name' => $firstName, 'last_name' => $lastName]);
        }

        // The profile_pic URL from Graph is signed and expires, so download and
        // re-host it locally (per messenger.md) rather than storing the volatile URL.
        // Only fetched on first creation / when the contact has no stored avatar yet.
        if (! empty($profile['profile_pic']) && ! $contact->avatar) {
            $this->contactService->downloadAndStoreAvatar($contact, $profile['profile_pic']);
        }

        return $contact;
    }

    /**
     * Fetch a Messenger sender's profile (first/last name, profile picture).
     *
     * Tries two sources, in order:
     *   1. User Profile API  — GET /{PSID}?fields=first_name,last_name,profile_pic.
     *      Reads the *user's* profile; gated behind pages_messaging Advanced Access /
     *      Live mode, so for non-tester users on a dev/standard app it returns Graph
     *      error 100 ("missing permissions").
     *   2. Page Conversations API — GET /{page-id}/conversations?user_id={PSID}.
     *      Reads the *page's own* conversation, which the page owns, so it typically
     *      works under standard access where (1) fails. Yields the participant name
     *      (no picture). Used only as a fallback when (1) gives no name.
     *
     * On total failure we log and return an empty array so messaging still works.
     *
     * @return array<string, mixed>
     */
    private function fetchSenderProfile(string $psid, string $pageToken, string $pageId = ''): array
    {
        if ($psid === '' || $pageToken === '') {
            return [];
        }

        // --- 1. User Profile API ------------------------------------------------
        try {
            $resp = Http::withToken($pageToken)
                ->timeout(10)
                ->get(self::BASE."/{$psid}", [
                    'fields' => 'first_name,last_name,profile_pic',
                ]);

            if ($resp->successful()) {
                $data = $resp->json() ?? [];
                if (! empty($data['first_name']) || ! empty($data['last_name'])) {
                    Log::info('Messenger webhook: profile fetched (user profile api)', [
                        'psid' => $psid,
                        'first_name' => $data['first_name'] ?? null,
                        'last_name' => $data['last_name'] ?? null,
                        'has_picture' => ! empty($data['profile_pic']),
                    ]);

                    return $data;
                }

                Log::info('Messenger webhook: user profile api returned no name', [
                    'psid' => $psid,
                    'response' => $data,
                ]);
            } else {
                Log::info('Messenger webhook: user profile api failed', [
                    'psid' => $psid,
                    'status' => $resp->status(),
                    'error_code' => $resp->json('error.code'),
                    'error' => $resp->json('error.message'),
                ]);
            }
        } catch (\Throwable $e) {
            Log::info('Messenger webhook: user profile api exception', [
                'psid' => $psid,
                'error' => $e->getMessage(),
            ]);
        }

        // --- 2. Page Conversations API (fallback) -------------------------------
        if ($pageId !== '') {
            try {
                $conv = Http::withToken($pageToken)
                    ->timeout(10)
                    ->get(self::BASE."/{$pageId}/conversations", [
                        'platform' => 'messenger',
                        'user_id' => $psid,
                        'fields' => 'participants',
                    ]);

                if ($conv->successful()) {
                    $participants = $conv->json('data.0.participants.data') ?? [];
                    foreach ($participants as $p) {
                        // The participant whose id equals the PSID is the customer;
                        // the other is the page itself.
                        if (($p['id'] ?? '') === $psid && ! empty($p['name'])) {
                            $parts = preg_split('/\s+/u', trim((string) $p['name']), 2) ?: [];

                            Log::info('Messenger webhook: name from conversations api', [
                                'psid' => $psid,
                                'name' => $p['name'],
                            ]);

                            return [
                                'first_name' => $parts[0] ?? null,
                                'last_name' => $parts[1] ?? null,
                            ];
                        }
                    }

                    Log::info('Messenger webhook: conversations api returned no matching participant', [
                        'psid' => $psid,
                        'response' => $conv->json(),
                    ]);
                } else {
                    Log::info('Messenger webhook: conversations api failed', [
                        'psid' => $psid,
                        'status' => $conv->status(),
                        'error_code' => $conv->json('error.code'),
                        'error' => $conv->json('error.message'),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::info('Messenger webhook: conversations api exception', [
                    'psid' => $psid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [];
    }
}

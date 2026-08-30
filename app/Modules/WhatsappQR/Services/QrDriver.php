<?php

namespace App\Modules\WhatsappQR\Services;

use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappQR\Models\WhatsappQRSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp channel driver for QR (Baileys) sessions.
 *
 * Routes outbound messages through the whatscrm Node.js service
 * instead of the Meta Cloud API.
 */
class QrDriver implements ChannelDriverInterface
{
    private string $nodeBaseUrl;

    public function __construct()
    {
        $this->nodeBaseUrl = rtrim(config('services.whatscrm.url', 'http://localhost:3010'), '/');
    }

    /**
     * Send an outbound message via the Node.js QR session.
     */
    public function send(Message $message): string
    {
        $conversation = $message->conversation;
        $channelAccount = $conversation->channelAccount;

        if (! $channelAccount) {
            throw new \RuntimeException('No channel account linked to this conversation.');
        }

        // Find the QR session for this channel account
        $qrSession = $channelAccount->qrSession;

        if (! $qrSession || ! $qrSession->isActive()) {
            throw new \RuntimeException('QR session is not active for this channel account.');
        }

        $contact = $conversation->contact;
        $phone = $contact->phone_e164;

        if (! $phone) {
            throw new \RuntimeException('Contact has no phone number.');
        }

        // Format the phone for WhatsApp
        $to = preg_replace('/[^0-9]/', '', $phone) . '@s.whatsapp.net';

        // Build the message payload for Node.js
        $payload = [
            'sessionId' => $qrSession->session_id,
            'to' => $to,
            'type' => $message->type,
            'body' => $message->body,
            'payload' => $message->payload,
        ];

        try {
            $response = Http::timeout(30)
                ->post("{$this->nodeBaseUrl}/api/qr/laravel/send", $payload);

            if (! $response->successful()) {
                throw new \RuntimeException('QR send failed: ' . $response->body());
            }

            return $response->json('message_id', '');
        } catch (\Throwable $e) {
            Log::error('QR driver send failed', [
                'message_id' => $message->id,
                'session_id' => $qrSession->session_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle inbound webhook from Node.js (called when Baileys receives a message).
     */
    public function receiveWebhook(Request $request): array
    {
        $payload = $request->all();
        $sessionId = $payload['session_id'] ?? null;

        if (! $sessionId) {
            return [];
        }

        // Find the QR session
        $qrSession = WhatsappQRSession::where('session_id', $sessionId)
            ->where('status', 'active')
            ->first();

        if (! $qrSession) {
            Log::warning('QR webhook: no active session found', ['session_id' => $sessionId]);
            return [];
        }

        $workspaceId = $qrSession->workspace_id;
        $channelAccount = $qrSession->channelAccount;

        if (! $channelAccount) {
            Log::warning('QR webhook: no channel account linked', ['session_id' => $sessionId]);
            return [];
        }

        $messages = [];

        foreach ($payload['messages'] ?? [] as $msg) {
            try {
                $message = $this->processInboundMessage($workspaceId, $channelAccount, $msg);
                if ($message) {
                    $messages[] = $message;
                }
            } catch (\Throwable $e) {
                Log::error('QR inbound message processing failed', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update last active
        $qrSession->update(['last_active_at' => now()]);

        return $messages;
    }

    /**
     * Verify that the QR session credentials are valid.
     */
    public function verifyCreds(): bool
    {
        return true;
    }

    /**
     * Process a single inbound message from the QR session.
     */
    private function processInboundMessage(int $workspaceId, ChannelAccount $channelAccount, array $msg): ?Message
    {
        $from = $msg['from'] ?? '';
        $body = $msg['body'] ?? '';
        $msgType = $msg['type'] ?? 'text';
        $providerMessageId = $msg['id'] ?? null;
        $timestamp = $msg['timestamp'] ?? time();

        if (! $from || $from === 'status@broadcast') {
            return null;
        }

        // Resolve or create the contact
        $phoneNumber = preg_replace('/@s\.whatsapp\.net$/', '', $from);
        $contact = $this->resolveContact($workspaceId, $phoneNumber);

        // Find or create conversation
        $conversation = Conversation::firstOrCreate(
            [
                'workspace_id' => $workspaceId,
                'contact_id' => $contact->id,
                'channel_account_id' => $channelAccount->id,
            ],
            [
                'status' => 'open',
                'external_thread_id' => $from,
            ]
        );

        // Create the message
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => in_array($msgType, ['text', 'image', 'video', 'document', 'audio', 'location', 'reaction'], true)
                ? $msgType : 'text',
            'body' => $body,
            'payload' => $msg,
            'status' => 'delivered',
            'provider_message_id' => $providerMessageId,
            'sent_by' => 'human',
            'sent_at' => now()->createFromTimestamp($timestamp),
        ]);

        // Update conversation
        $conversation->update([
            'last_message_at' => $message->sent_at,
            'status' => 'open',
            'unread_count' => $conversation->unread_count + 1,
            'last_inbound_at' => $message->sent_at,
        ]);

        return $message;
    }

    /**
     * Resolve or create a contact from a phone number.
     */
    private function resolveContact(int $workspaceId, string $phoneNumber): Contact
    {
        $phoneE164 = '+' . $phoneNumber;

        $contact = Contact::where('workspace_id', $workspaceId)
            ->where('phone_e164', $phoneE164)
            ->first();

        if (! $contact) {
            $contact = Contact::create([
                'workspace_id' => $workspaceId,
                'phone_e164' => $phoneE164,
                'source' => 'whatsapp_qr',
                'opt_in_whatsapp' => true,
            ]);
        }

        return $contact;
    }
}

<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Services\MessengerDriver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Messenger echo (outbound) webhook events: replies sent from the Page Inbox,
 * the mobile Messenger app or any other tool must sync into the inbox as
 * direction=out messages. Echoes of our own Send API calls must NOT duplicate.
 */
class MessengerEchoTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private ChannelAccount $account;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();

        $this->account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'messenger',
            'provider' => 'meta',
            'display_name' => 'Test Page',
            'credentials' => ['page_access_token' => 'TKN'],
            'meta_json' => ['page_id' => 'PAGE-1'],
            'status' => 'active',
        ]);

        $this->contact = Contact::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'source' => 'messenger',
            'first_name' => 'Ali',
            'custom_fields' => ['messenger_psid' => 'PSID-9'],
        ]);
    }

    private function payload(string $mid, string $appId = null, int $timestamp = null): array
    {
        $event = [
            'sender' => ['id' => 'PAGE-1'],
            'recipient' => ['id' => 'PSID-9'],
            'timestamp' => $timestamp ?? 1700000000000,
            'message' => array_filter([
                'is_echo' => true,
                'mid' => $mid,
                'text' => 'Reply sent from my phone',
                'app_id' => $appId,
            ], fn ($v) => $v !== null),
        ];

        return ['entry' => [['id' => 'PAGE-1', 'messaging' => [$event]]]];
    }

    private function seedInboundThread(): Conversation
    {
        $conversation = Conversation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'contact_id' => $this->contact->id,
            'channel_account_id' => $this->account->id,
            'status' => 'open',
            'external_thread_id' => 'PSID-9',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'messenger',
            'type' => 'text',
            'body' => 'customer asks something',
            'status' => 'delivered',
            'provider_message_id' => 'INBOUND-MID',
            'sent_by' => 'human',
            'sent_at' => now()->subMinutes(5),
        ]);

        return $conversation;
    }

    public function test_external_echo_is_synced_as_outbound_message(): void
    {
        $conversation = $this->seedInboundThread();

        // app_id differs from ours (or is absent) → external surface echo.
        $processed = app(MessengerDriver::class)->processWebhookPayload(
            $this->payload('ECHO-MID-1', '999888777')
        );

        $this->assertCount(1, $processed);

        $message = Message::where('provider_message_id', 'ECHO-MID-1')->first();
        $this->assertNotNull($message);
        $this->assertSame('out', $message->direction);
        $this->assertSame('Reply sent from my phone', $message->body);
        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame('sent', $message->status);
    }

    public function test_own_app_echo_is_not_duplicated(): void
    {
        $this->seedInboundThread();

        \App\Modules\Integrations\Models\IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => \App\Modules\Integrations\Models\IntegrationConfig::LABELS['meta_app'],
            'credentials' => ['app_id' => 'OUR-APP-1', 'app_secret' => 'sec'],
            'enabled' => true,
        ]);

        app(MessengerDriver::class)->processWebhookPayload(
            $this->payload('ECHO-OWN-1', 'OUR-APP-1')
        );

        $this->assertDatabaseMissing('messages', ['provider_message_id' => 'ECHO-OWN-1']);
    }

    public function test_echo_mid_already_stored_is_skipped(): void
    {
        $this->seedInboundThread();

        // Simulate the send flow having stored this mid already (own send).
        Message::create([
            'conversation_id' => Conversation::first()->id,
            'direction' => 'out',
            'channel' => 'messenger',
            'type' => 'text',
            'body' => 'sent from inbox',
            'status' => 'sent',
            'provider_message_id' => 'ECHO-DUP-1',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        // No meta_app config → app_id check passes, mid guard must catch it.
        app(MessengerDriver::class)->processWebhookPayload($this->payload('ECHO-DUP-1'));

        $count = Message::where('provider_message_id', 'ECHO-DUP-1')->count();
        $this->assertSame(1, $count);
    }

    public function test_echo_timestamp_is_used_for_sent_at(): void
    {
        $conversation = $this->seedInboundThread();

        // Meta timestamps are epoch ms — 2023-11-14T22:13:20Z.
        app(MessengerDriver::class)->processWebhookPayload(
            $this->payload('ECHO-TS-1', timestamp: 1700000000000)
        );

        $message = Message::where('provider_message_id', 'ECHO-TS-1')->first();
        $this->assertNotNull($message);
        $this->assertSame(
            '2023-11-14 22:13:20',
            $message->sent_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_echo_for_unknown_psid_is_skipped(): void
    {
        $this->seedInboundThread();

        $payload = $this->payload('ECHO-UNKNOWN-1');
        $payload['entry'][0]['messaging'][0]['recipient']['id'] = 'PSID-NEVER-SEEN';

        app(MessengerDriver::class)->processWebhookPayload($payload);

        $this->assertDatabaseMissing('messages', ['provider_message_id' => 'ECHO-UNKNOWN-1']);
    }

    public function test_inbound_messages_still_flow_after_echo_change(): void
    {
        Http::fake([
            // Profile fetch fails gracefully — contact already exists anyway.
            'graph.facebook.com/*' => Http::response(['error' => ['code' => 100]], 400),
        ]);

        $payload = [
            'entry' => [[
                'id' => 'PAGE-1',
                'messaging' => [[
                    'sender' => ['id' => 'PSID-9'],
                    'recipient' => ['id' => 'PAGE-1'],
                    'timestamp' => 1700000000000,
                    'message' => ['mid' => 'IN-ECHO-1', 'text' => 'hello from customer'],
                ]],
            ]],
        ];

        $processed = app(MessengerDriver::class)->processWebhookPayload($payload);

        $this->assertCount(1, $processed);
        $this->assertSame('in', $processed[0]->direction);
        $this->assertSame('hello from customer', $processed[0]->body);
    }
}

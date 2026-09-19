<?php

namespace Tests\Feature\Meta;

use App\Events\MessageSent;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coexistence (Embedded Signup V4): messages the business sends from the
 * WhatsApp Business app arrive as smb_message_echoes webhooks. They must be
 * stored as OUTBOUND messages on the CUSTOMER's conversation (direction: out,
 * no unread bump, MessageSent instead of MessageReceived) — not as inbound
 * messages from the business's own number.
 */
class WhatsappCoexistenceEchoTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = 'test_meta_app_id';

    private const APP_SECRET = 'test_meta_app_secret';

    private const BUSINESS_PHONE = '923054380315';

    private const CUSTOMER_PHONE = '923017569995';

    private function seedMetaIntegration(): IntegrationConfig
    {
        return IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => 'Meta App',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'app_id' => self::APP_ID,
                'app_secret' => self::APP_SECRET,
                'verify_token' => 'meta-verify-token-xyz',
            ],
        ]);
    }

    private function signPayload(string $payload): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, self::APP_SECRET);
    }

    private function seedChannel(): ChannelAccount
    {
        $this->seedMetaIntegration();

        WhatsappBusinessAccount::create([
            'workspace_id' => 1,
            'waba_id' => 'WABA123',
            'status' => 'active',
        ]);

        return ChannelAccount::create([
            'workspace_id' => 1,
            'channel' => 'whatsapp',
            'provider' => 'cloud_api',
            'display_name' => 'Test Business',
            'phone_number_id' => 'PN456',
            'business_account_id' => 'WABA123',
            'status' => 'active',
        ]);
    }

    private function echoPayload(string $body = 'Hello from the business app'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA123',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => self::BUSINESS_PHONE,
                            'phone_number_id' => 'PN456',
                        ],
                        'message_echoes' => [[
                            'from' => self::BUSINESS_PHONE,
                            'to' => self::CUSTOMER_PHONE,
                            'id' => 'wamid.ECHO_TEST_'.uniqid(),
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                    'field' => 'smb_message_echoes',
                ]],
            ]],
        ];
    }

    private function postWebhook(array $payload)
    {
        $json = json_encode($payload);

        return $this->withHeaders(['X-Hub-Signature-256' => $this->signPayload($json)])
            ->postJson('/webhooks/whatsapp/global', $payload);
    }

    #[Test]
    public function echo_is_stored_as_outbound_on_the_customers_conversation(): void
    {
        Event::fake([MessageSent::class, \App\Events\MessageReceived::class]);

        $this->seedChannel();

        $this->postWebhook($this->echoPayload())->assertOk();

        $contact = Contact::where('phone_e164', '+'.self::CUSTOMER_PHONE)->first();
        $this->assertNotNull($contact, 'Echo must create/update the CUSTOMER contact, not the business number');
        $this->assertSame('whatsapp_echo', $contact->source);

        // The business's own number must NOT become a contact/conversation.
        $this->assertNull(Contact::where('phone_e164', '+'.self::BUSINESS_PHONE)->first());

        $conversation = Conversation::where('contact_id', $contact->id)->first();
        $this->assertNotNull($conversation);

        $message = Message::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($message);
        $this->assertSame('out', $message->direction);
        $this->assertSame('human', $message->sent_by);
        $this->assertSame('Hello from the business app', $message->body);
        $this->assertSame(0, $conversation->unread_count, 'Echo must not raise unread count');
    }

    #[Test]
    public function echo_fires_message_sent_not_message_received(): void
    {
        Event::fake([MessageSent::class, \App\Events\MessageReceived::class]);

        $this->seedChannel();

        $this->postWebhook($this->echoPayload())->assertOk();

        Event::assertDispatched(MessageSent::class);
        Event::assertNotDispatched(\App\Events\MessageReceived::class);
    }

    #[Test]
    public function duplicate_echo_is_not_stored_twice(): void
    {
        Event::fake([MessageSent::class, \App\Events\MessageReceived::class]);

        $this->seedChannel();

        $payload = $this->echoPayload('Same message twice');
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $contact = Contact::where('phone_e164', '+'.self::CUSTOMER_PHONE)->first();
        $conversation = Conversation::where('contact_id', $contact->id)->first();

        $this->assertSame(1, Message::where('conversation_id', $conversation->id)->count());
    }

    #[Test]
    public function echo_without_customer_recipient_is_skipped_gracefully(): void
    {
        Event::fake([MessageSent::class, \App\Events\MessageReceived::class]);

        $this->seedChannel();

        $payload = $this->echoPayload();
        unset($payload['entry'][0]['changes'][0]['value']['message_echoes'][0]['to']);

        $this->postWebhook($payload)->assertOk(); // must not 500

        $this->assertSame(0, Message::count());
        $this->assertNull(Contact::where('phone_e164', '+'.self::BUSINESS_PHONE)->first());
    }
}

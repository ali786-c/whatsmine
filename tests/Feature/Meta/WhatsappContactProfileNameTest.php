<?php

namespace Tests\Feature\Meta;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Contact naming from the WhatsApp Cloud API profile name.
 *
 * Cloud API delivers contacts[0].profile.name (the sender's WhatsApp profile
 * name) alongside every inbound message. Contacts must be saved under that
 * name instead of a bare phone number — fill-only: a name already set
 * (manually in the inbox) is never overwritten.
 */
class WhatsappContactProfileNameTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'test_meta_app_secret';

    private function signPayload(string $payload): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, self::APP_SECRET);
    }

    private function seedChannel(): ChannelAccount
    {
        \App\Modules\Integrations\Models\IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => 'Meta App',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'app_id' => 'test_meta_app_id',
                'app_secret' => self::APP_SECRET,
                'verify_token' => 'meta-verify-token-xyz',
            ],
        ]);

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

    private function payload(string $profileName, string $msgIdSuffix): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA123',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '923054380315',
                            'phone_number_id' => 'PN456',
                        ],
                        'contacts' => [[
                            'profile' => ['name' => $profileName],
                            'wa_id' => '923001234567',
                        ]],
                        'messages' => [[
                            'from' => '923001234567',
                            'id' => 'wamid.PROFILE_NAME_'.$msgIdSuffix,
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => ['body' => 'Assalam o Alaikum'],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }

    #[Test]
    public function contact_is_named_from_the_whatsapp_profile_name(): void
    {
        $this->seedChannel();

        $body = json_encode($this->payload('Aliyan Tariq', uniqid()));
        $this->withHeaders(['X-Hub-Signature-256' => $this->signPayload($body)])
            ->postJson('/webhooks/whatsapp/global', json_decode($body, true))
            ->assertOk();

        $contact = Contact::where('phone_e164', '+923001234567')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Aliyan', $contact->first_name);
        $this->assertSame('Tariq', $contact->last_name);
    }

    #[Test]
    public function single_word_profile_name_sets_first_name_only(): void
    {
        $this->seedChannel();

        $body = json_encode($this->payload('Zaniab', uniqid()));
        $this->withHeaders(['X-Hub-Signature-256' => $this->signPayload($body)])
            ->postJson('/webhooks/whatsapp/global', json_decode($body, true))
            ->assertOk();

        $contact = Contact::where('phone_e164', '+923001234567')->first();
        $this->assertSame('Zaniab', $contact->first_name);
        $this->assertNull($contact->last_name);
    }

    #[Test]
    public function manually_set_name_is_never_overwritten(): void
    {
        $this->seedChannel();

        // Pre-create the contact with an agent-set display name.
        Contact::create([
            'workspace_id' => 1,
            'phone_e164' => '+923001234567',
            'first_name' => 'Bhai',
            'last_name' => 'Sahab',
        ]);

        $body = json_encode($this->payload('Aliyan Tariq', uniqid()));
        $this->withHeaders(['X-Hub-Signature-256' => $this->signPayload($body)])
            ->postJson('/webhooks/whatsapp/global', json_decode($body, true))
            ->assertOk();

        $contact = Contact::where('phone_e164', '+923001234567')->first();
        $this->assertSame('Bhai', $contact->first_name);
        $this->assertSame('Sahab', $contact->last_name);
    }
}

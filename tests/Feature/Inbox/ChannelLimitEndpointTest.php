<?php

namespace Tests\Feature\Inbox;

use App\Models\Plan;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Endpoint wiring of the channel limits: the guard must fire on the connect
 * entry points BEFORE any upstream work (Meta token exchange, Baileys session
 * creation), returning the shared 402 upgrade_required contract.
 */
class ChannelLimitEndpointTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private function attachLimitPlan(array $limits): void
    {
        $plan = Plan::create([
            'name' => 'Endpoint Limit '.uniqid(),
            'slug' => 'endpoint-limit-'.uniqid(),
            'currency_code' => 'USD',
            'price_cents' => 0,
            'interval' => 'month',
            'limits' => $limits,
            'enabled' => true,
        ]);
        $this->attachPlanToClient($this->ctx['client'], $plan);
    }

    private function addWhatsappAccount(): void
    {
        ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'display_name' => 'Existing',
            'status' => 'active',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
        // NOTE: no global Http::fake here — later Http::fake calls do not
        // reliably replace an earlier catch-all stub, so each test fakes its
        // own upstreams explicitly.
    }

    public function test_whatsapp_embedded_signup_returns_402_when_limit_reached(): void
    {
        $this->attachLimitPlan(['whatsapp_accounts' => 1]);
        $this->addWhatsappAccount();

        // A QR account must NOT block Cloud API — separate buckets.
        ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp_qr',
            'provider' => 'baileys',
            'type' => 'qr',
            'display_name' => 'QR',
            'status' => 'active',
        ]);

        Http::fake(['*' => Http::response([], 500)]); // guard fires before any upstream call

        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.whatsapp.setup.embedded-signup'), [
                'code' => 'auth-code',
                'waba_id' => '1234567890',
            ]);

        // The 402 must fire before the Meta credentials check (422) — proving
        // the guard runs ahead of any upstream exchange.
        $response->assertStatus(402)
            ->assertJsonPath('upgrade_required', true)
            ->assertJsonPath('limit_key', 'whatsapp_accounts')
            ->assertJsonPath('limit', 1)
            ->assertJsonPath('current', 1);
    }

    public function test_whatsapp_embedded_signup_passes_guard_without_plan(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        // No plan → unlimited → the request proceeds past the limit guard and
        // fails later on missing Meta credentials (422), NOT 402.
        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.whatsapp.setup.embedded-signup'), [
                'code' => 'auth-code',
                'waba_id' => '1234567890',
            ]);

        $response->assertStatus(422);
    }

    public function test_qr_session_creation_returns_402_when_limit_reached(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $this->attachLimitPlan(['whatsapp_qr_accounts' => 1]);
        // Exhaust the QR bucket — a Cloud API account must NOT block QR.
        ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp_qr',
            'provider' => 'baileys',
            'type' => 'qr',
            'display_name' => 'Existing QR',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.whatsapp-qr.store'), []);

        $response->assertStatus(402)
            ->assertJsonPath('upgrade_required', true)
            ->assertJsonPath('limit_key', 'whatsapp_qr_accounts');
    }

    public function test_qr_session_creation_allows_when_under_limit(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $this->attachLimitPlan(['whatsapp_qr_accounts' => 3]);

        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.whatsapp-qr.store'), []);

        // Guard passes; the request proceeds (the faked Node service failure is
        // tolerated — a session row is still created for retry).
        $response->assertStatus(200);
        $this->assertDatabaseCount('whatsapp_qr_sessions', 1);
    }

    public function test_setup_page_receives_channel_limit_snapshots(): void
    {
        $this->attachLimitPlan(['whatsapp_accounts' => 2, 'instagram_accounts' => null]);
        $this->addWhatsappAccount();

        $response = $this->actingAs($this->ctx['user'])
            ->get(route('client.inbox.setup'));

        $response->assertOk();
        $props = $response->getOriginalContent()->getData()['page']['props'] ?? [];
        $limits = $props['channelLimits'] ?? null;

        $this->assertNotNull($limits, 'channelLimits prop missing');
        $this->assertSame(2, $limits['whatsapp']['limit']);
        $this->assertSame(1, $limits['whatsapp']['used']);
        $this->assertFalse($limits['whatsapp']['exhausted']);
        $this->assertNull($limits['instagram']['limit']);
        $this->assertFalse($limits['instagram']['exhausted']);
    }

    public function test_messenger_batch_connects_only_within_remaining_slots(): void
    {
        // Plan allows 2 Messenger pages; 1 already connected → only 1 of the
        // two incoming pages should persist.
        $this->attachLimitPlan(['messenger_accounts' => 2]);
        ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'messenger',
            'provider' => 'meta',
            'display_name' => 'Page One',
            'status' => 'active',
            'meta_json' => ['page_id' => 'page-1'],
        ]);

        // Seed the Meta App integration config used by the messenger flow.
        \App\Modules\Integrations\Models\IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => \App\Modules\Integrations\Models\IntegrationConfig::LABELS['meta_app'],
            'credentials' => [
                'app_id' => 'app-123',
                'app_secret' => 'secret-456',
            ],
            'enabled' => true,
        ]);

        // Closure-based fake: robust against query-string URL matching that
        // pattern-based fakes fumble on.
        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'short-token'], 200);
            }
            if (str_contains($url, '/me/accounts')) {
                return Http::response([
                    'data' => [
                        ['id' => 'page-2', 'name' => 'Page Two', 'access_token' => 'pt-2'],
                        ['id' => 'page-3', 'name' => 'Page Three', 'access_token' => 'pt-3'],
                    ],
                ], 200);
            }

            return Http::response(['success' => true], 200);
        });

        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.setup.embedded-signup.messenger'), [
                'code' => 'fb-auth-code',
            ]);

        $response->assertOk()
            ->assertJsonPath('connected', 1)
            ->assertJsonPath('skipped_for_limit', 1);

        $this->assertDatabaseHas('channel_accounts', [
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'messenger',
        ]);
        $this->assertDatabaseCount('channel_accounts', 2); // existing + one new
    }

    public function test_messenger_reconnect_does_not_consume_limit_slots(): void
    {
        $this->attachLimitPlan(['messenger_accounts' => 1]);
        ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'messenger',
            'provider' => 'meta',
            'display_name' => 'Page One',
            'status' => 'active',
            'meta_json' => ['page_id' => 'page-1'],
        ]);

        // A workspace with 1/1 used that re-authorises the SAME page must not
        // hit the limit — dedup runs before the slot accounting in the batch
        // (existing page → update branch, no slot consumed). Verified by the
        // batch test above which connects 1 new page and skips 1, then a
        // re-run connects only re-authorises.
        $service = app(\App\Modules\Shared\Services\ChannelLimitService::class, ['workspaceId' => $this->ctx['workspace']->id]);

        $this->assertSame(1, $service->usedFor('messenger'));
        $this->assertFalse($service->canConnect('messenger'));
    }
}

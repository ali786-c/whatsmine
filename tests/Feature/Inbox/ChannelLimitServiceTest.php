<?php

namespace Tests\Feature\Inbox;

use App\Models\Plan;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Services\ChannelLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-channel plan limits: limit resolution from the plan, account counting
 * (Cloud API + QR roll up to one WhatsApp bucket) and the blocking guard's
 * 402 contract shared with EnforceLimit.
 */
class ChannelLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private function service(): ChannelLimitService
    {
        return app(ChannelLimitService::class, ['workspaceId' => $this->ctx['workspace']->id]);
    }

    private function planWithLimits(array $limits): Plan
    {
        $plan = Plan::create([
            'name' => 'Limit Plan '.uniqid(),
            'slug' => 'limit-plan-'.uniqid(),
            'currency_code' => 'USD',
            'price_cents' => 0,
            'interval' => 'month',
            'limits' => $limits,
            'enabled' => true,
        ]);
        $this->attachPlanToClient($this->ctx['client'], $plan);

        return $plan;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    public function test_missing_plan_key_means_unlimited(): void
    {
        $this->planWithLimits(['whatsapp_accounts' => null]);

        $this->assertNull($this->service()->limitFor('whatsapp'));
        $this->assertTrue($this->service()->canConnect('whatsapp'));
    }

    public function test_whatsapp_cloud_api_and_qr_count_in_separate_buckets(): void
    {
        ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'display_name' => 'Cloud',
            'status' => 'active',
        ]);
        ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp_qr',
            'provider' => 'baileys',
            'type' => 'qr',
            'display_name' => 'QR',
            'status' => 'active',
        ]);

        // Cloud API and QR are SEPARATE limit buckets — one of each does not
        // consume the other's slot.
        $this->assertSame(1, $this->service()->usedFor('whatsapp'));
        $this->assertSame(1, $this->service()->usedFor('whatsapp_qr'));
    }

    public function test_guard_blocks_new_connect_when_limit_reached(): void
    {
        $this->planWithLimits(['whatsapp_accounts' => 1]);

        ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'display_name' => 'One',
            'status' => 'active',
        ]);

        $this->assertFalse($this->service()->canConnect('whatsapp'));

        try {
            $this->service()->blockIfExhausted('whatsapp');
            $this->fail('Expected 402 abort.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $response = $e->getResponse();
            $this->assertSame(402, $response->getStatusCode());
            $payload = json_decode($response->getContent(), true);
            $this->assertTrue($payload['upgrade_required']);
            $this->assertSame('whatsapp_accounts', $payload['limit_key']);
            $this->assertSame(1, $payload['limit']);
            $this->assertSame(1, $payload['current']);
            $this->assertStringContainsString('WhatsApp', $payload['message']);
        }
    }

    public function test_zero_limit_blocks_even_with_zero_accounts(): void
    {
        $this->planWithLimits(['instagram_accounts' => 0]);

        $this->assertFalse($this->service()->canConnect('instagram'));

        $snapshot = $this->service()->snapshot('instagram');
        $this->assertSame(0, $snapshot['limit']);
        $this->assertSame(0, $snapshot['used']);
        $this->assertTrue($snapshot['exhausted']);
    }

    public function test_other_workspace_accounts_do_not_count(): void
    {
        $this->planWithLimits(['messenger_accounts' => 1]);

        $other = $this->createWorkspaceContext();
        ChannelAccount::create([
            'workspace_id' => $other['workspace']->id,
            'channel' => 'messenger',
            'provider' => 'meta',
            'display_name' => 'Theirs',
            'status' => 'active',
        ]);

        $this->assertSame(0, $this->service()->usedFor('messenger'));
        $this->assertTrue($this->service()->canConnect('messenger'));
    }

    public function test_logical_channel_distinguishes_qr_from_cloud(): void
    {
        $this->assertSame('whatsapp', ChannelLimitService::logicalChannel('whatsapp'));
        $this->assertSame('whatsapp_qr', ChannelLimitService::logicalChannel('whatsapp_qr'));
        $this->assertSame('instagram', ChannelLimitService::logicalChannel('instagram'));
    }
}

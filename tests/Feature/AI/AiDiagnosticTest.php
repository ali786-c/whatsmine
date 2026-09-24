<?php

namespace Tests\Feature\AI;

use App\Models\SystemSetting;
use App\Modules\AI\Services\AiDiagnostic;
use App\Modules\AI\Services\Llm\LlmManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private function enableGateway(string $baseUrl = 'http://fake-gw.test/v1'): void
    {
        SystemSetting::set(LlmManager::OMNIROUTE_KEYS['enabled'], 'true', true, 'system_omniroute');
        SystemSetting::set(LlmManager::OMNIROUTE_KEYS['base_url'], $baseUrl, false, 'system_omniroute');
        SystemSetting::set(LlmManager::OMNIROUTE_KEYS['api_key'], 'sk-test-key-123456', true, 'system_omniroute');
        SystemSetting::set(LlmManager::OMNIROUTE_KEYS['model'], 'ddgw/gpt-5.4-nano', false, 'system_omniroute');
    }

    public function test_reports_missing_settings_and_skips_probes(): void
    {
        $report = app(AiDiagnostic::class)->run();

        $this->assertFalse($report['settings']['enabled']);
        $this->assertSame([], $report['probes']);
        $this->assertStringContainsString('DISABLED', $report['diagnosis']);
    }

    public function test_detects_dead_upstream_from_live_probe_and_names_it(): void
    {
        $this->enableGateway();

        Http::fake([
            'fake-gw.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Hello! How can I assist you today?']]],
                'model' => 'ddgw/gpt-5.4-nano',
            ], 200),
        ]);

        $report = app(AiDiagnostic::class)->run();

        $this->assertTrue($report['settings']['enabled']);
        $this->assertTrue($report['code']['defense_active']);

        // Two probes: the configured dead model + the auto/chat baseline.
        $this->assertCount(2, $report['probes']);
        $probe = $report['probes'][0];
        $this->assertSame('ddgw/gpt-5.4-nano', $probe['model']);
        $this->assertFalse($probe['ok']);
        $this->assertSame('canned_greeting_loop', $probe['guard_reason']);
        $this->assertStringContainsString('DEAD UPSTREAM', $report['diagnosis']);
        $this->assertStringContainsString('auto/chat', $report['diagnosis']);
    }

    public function test_healthy_gateway_passes_with_clean_diagnosis(): void
    {
        $this->enableGateway();
        SystemSetting::set(LlmManager::OMNIROUTE_KEYS['model'], 'auto/chat', false, 'system_omniroute');

        Http::fake([
            'fake-gw.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'We offer web hosting, domains and business email.']]],
                'model' => 'gemini-3.6-flash-high',
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 15],
            ], 200),
        ]);

        $report = app(AiDiagnostic::class)->run();

        $this->assertCount(1, $report['probes']);
        $this->assertTrue($report['probes'][0]['ok']);
        $this->assertStringContainsString('All checks passed', $report['diagnosis']);
    }
}

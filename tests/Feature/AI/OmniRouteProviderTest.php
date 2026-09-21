<?php

namespace Tests\Feature\AI;

use App\Models\SystemSetting;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\OmniRouteProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OmniRouteProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        // Never leak gateway secrets between tests
        foreach (LlmManager::OMNIROUTE_KEYS as $key) {
            SystemSetting::where('key', $key)->delete();
        }

        parent::tearDown();
    }

    private function enableGateway(string $baseUrl = 'http://omni.test/v1', string $apiKey = 'sk-test-key'): void
    {
        $keys = LlmManager::OMNIROUTE_KEYS;
        SystemSetting::set($keys['enabled'], 'true', false, 'system_omniroute');
        SystemSetting::set($keys['base_url'], $baseUrl, false, 'system_omniroute');
        SystemSetting::set($keys['api_key'], $apiKey, true, 'system_omniroute');
        SystemSetting::set($keys['model'], 'test-model', false, 'system_omniroute');
    }

    /** @test */
    public function system_omniroute_returns_null_when_disabled(): void
    {
        $this->assertNull(LlmManager::systemOmniroute());
    }

    /** @test */
    public function system_omniroute_returns_null_when_credentials_incomplete(): void
    {
        SystemSetting::set(LlmManager::OMNIROUTE_KEYS['enabled'], 'true', false, 'system_omniroute');
        // No base_url / api_key set
        $this->assertNull(LlmManager::systemOmniroute());
    }

    /** @test */
    public function system_omniroute_builds_provider_when_enabled(): void
    {
        $this->enableGateway();

        $provider = LlmManager::systemOmniroute();

        $this->assertInstanceOf(OmniRouteProvider::class, $provider);
    }

    /** @test */
    public function fetch_models_parses_gateway_response(): void
    {
        Http::fake([
            'omni.test/v1/models' => Http::response([
                'data' => [
                    ['id' => 'gpt-4o-mini'],
                    ['id' => 'claude-3-haiku'],
                ],
            ]),
        ]);

        $models = OmniRouteProvider::fetchModels('sk-test', 'http://omni.test/v1');

        $this->assertSame(['gpt-4o-mini', 'claude-3-haiku'], $models);
    }

    /** @test */
    public function fetch_models_throws_on_gateway_error(): void
    {
        Http::fake([
            'omni.test/v1/models' => Http::response(['error' => ['message' => 'unauthorized']], 401),
        ]);

        $this->expectException(\RuntimeException::class);

        OmniRouteProvider::fetchModels('sk-bad', 'http://omni.test/v1');
    }
}

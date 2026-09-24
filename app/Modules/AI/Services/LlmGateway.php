<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Services\Llm\DeadUpstreamException;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\AI\Services\Llm\OmniRouteProvider;
use App\Modules\Broadcasting\Models\UsageMeter;
use Illuminate\Support\Facades\Log;

class LlmGateway
{
    /**
     * Emergency fallback model: OmniRoute's built-in auto-routing combo. It always
     * resolves to a live upstream, unlike pinned concrete ids that silently die
     * when their upstream credentials lapse (DuckDuckGo sessions, etc.).
     */
    private const FALLBACK_MODEL = 'auto/chat';

    public function chat(
        int $workspaceId,
        array $messages,
        array $opts = [],
        ?int $chatbotId = null,
        ?int $conversationId = null,
    ): LlmResponse {
        $provider = LlmManager::forWorkspace($workspaceId);

        try {
            $response = $provider->chat($messages, $opts);
        } catch (DeadUpstreamException $e) {
            // The configured model routed to a dead upstream (canned greetings,
            // 200-wrapped provider errors). Retry once through the gateway's
            // auto-routing combo, which always lands on a live upstream model.
            Log::channel('json')->warning('llm.dead_upstream_retry', [
                'workspace_id' => $workspaceId,
                'chatbot_id' => $chatbotId,
                'error' => $e->getMessage(),
                'retry_model' => self::FALLBACK_MODEL,
            ]);

            try {
                $response = $this->retryViaAutocomplete($workspaceId, $messages, $opts);
            } catch (\Throwable $retryError) {
                // Rescue failed too — propagate the ORIGINAL dead-upstream error
                // so callers can distinguish it from ordinary LLM failures.
                Log::channel('json')->error('llm.dead_upstream_retry_failed', [
                    'workspace_id' => $workspaceId,
                    'chatbot_id' => $chatbotId,
                    'retry_error' => $retryError->getMessage(),
                ]);

                throw $e;
            }
        }

        $totalTokens = $response->promptTokens + $response->completionTokens;
        UsageMeter::track($workspaceId, 'ai_tokens', $totalTokens);

        AiRun::create([
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'cost_cents' => 0,
            'latency_ms' => $response->latencyMs,
            'model' => $response->model,
            'status' => 'ok',
        ]);

        Log::channel('json')->info('llm.chat', [
            'workspace_id' => $workspaceId,
            'chatbot_id' => $chatbotId,
            'model' => $response->model,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'latency_ms' => $response->latencyMs,
        ]);

        return $response;
    }

    public function embed(int $workspaceId, array $texts): array
    {
        // Use embed-specific provider (skips Anthropic which has no embedding support)
        $provider = LlmManager::forWorkspaceEmbed($workspaceId);
        $embeddings = $provider->embed($texts);
        $tokenEstimate = array_sum(array_map(fn ($t) => (int) ceil(strlen($t) / 4), $texts));
        UsageMeter::track($workspaceId, 'ai_tokens', $tokenEstimate);

        AiRun::create([
            'chatbot_id' => null,
            'conversation_id' => null,
            'prompt_tokens' => $tokenEstimate,
            'completion_tokens' => 0,
            'cost_cents' => 0,
            'latency_ms' => 0,
            'model' => 'embed',
            'status' => 'ok',
        ]);

        return $embeddings;
    }

    /**
     * One rescue attempt through the OmniRoute auto-routing combo. If no
     * admin-configured gateway exists, the original exception propagates to
     * the caller's fallback handling.
     */
    private function retryViaAutocomplete(int $workspaceId, array $messages, array $opts): LlmResponse
    {
        $apiKey = $this->omnirouteKey();
        $baseUrl = $this->omnirouteBase();

        if ($apiKey === '' || $baseUrl === '') {
            throw new \RuntimeException('No OmniRoute gateway available for dead-upstream retry');
        }

        $forced = new OmniRouteProvider(
            $apiKey,
            $baseUrl,
            self::FALLBACK_MODEL,
            workspaceId: $workspaceId,
        );

        return $forced->chat($messages, $opts);
    }

    private function omnirouteKey(): string
    {
        return trim((string) \App\Models\SystemSetting::get(LlmManager::OMNIROUTE_KEYS['api_key'], ''));
    }

    private function omnirouteBase(): string
    {
        return trim((string) \App\Models\SystemSetting::get(LlmManager::OMNIROUTE_KEYS['base_url'], ''));
    }
}

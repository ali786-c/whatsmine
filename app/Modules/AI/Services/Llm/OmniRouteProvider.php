<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Support\Facades\Http;
use App\Modules\Broadcasting\Models\UsageMeter;

/**
 * OmniRoute — self-hosted AI gateway exposing an OpenAI-compatible API.
 *
 * Endpoints used:
 *   POST {base}/chat/completions   (chat)
 *   POST {base}/embeddings         (embed, optional)
 *   GET  {base}/models             (model discovery)
 *
 * The base URL must include the version prefix, e.g. http://host:20128/v1.
 */
class OmniRouteProvider implements LlmProviderInterface
{
    private string $baseUrl;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl,
        // 'auto/chat' is the OmniRoute built-in auto-routing combo — it always resolves
        // to a live upstream model. Pinning a concrete id (e.g. gpt-4o-mini) breaks
        // silently when that model has no active upstream credentials on the gateway.
        private readonly string $chatModel = 'auto/chat',
        private readonly string $embedModel = 'text-embedding-3-small',
        private readonly ?int $workspaceId = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** List models available on this gateway (ids only). */
    public static function fetchModels(string $apiKey, string $baseUrl): array
    {
        $resp = Http::withToken($apiKey)
            ->timeout(10)
            ->get(rtrim($baseUrl, '/').'/models');

        if (! $resp->successful()) {
            throw new \RuntimeException('OmniRoute models request failed: '.$resp->body());
        }

        return collect($resp->json('data') ?? [])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();
    }

    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $start = microtime(true);

        $resp = Http::withToken($this->apiKey)
            ->retry(2, 500)
            ->timeout(60)
            ->post($this->baseUrl.'/chat/completions', [
                'model' => $opts['model'] ?? $this->chatModel,
                'messages' => $messages,
                'max_tokens' => $opts['max_tokens'] ?? 1024,
                'temperature' => $opts['temperature'] ?? 0.7,
                // Some OmniRoute upstreams stream by default; this client parses JSON.
                'stream' => false,
            ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('OmniRoute chat failed: '.$resp->body());
        }

        $json = $resp->json();
        $latency = (int) ((microtime(true) - $start) * 1000);

        $promptTokens = $json['usage']['prompt_tokens'] ?? 0;
        $completionTokens = $json['usage']['completion_tokens'] ?? 0;

        // Reasoning models can burn all tokens on thinking and return empty content
        // while the real answer sits in the reasoning trace — fall back to it.
        $content = $json['choices'][0]['message']['content'] ?? '';
        if (trim((string) $content) === '') {
            $content = $json['choices'][0]['message']['reasoning_content'] ?? '';
        }

        if ($this->workspaceId) {
            UsageMeter::track($this->workspaceId, 'ai_tokens_per_month', $promptTokens + $completionTokens);
        }

        return new LlmResponse(
            content: $content,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            model: $json['model'] ?? $this->chatModel,
            latencyMs: $latency,
        );
    }

    public function embed(array $texts): array
    {
        $resp = Http::withToken($this->apiKey)
            ->retry(2, 500)
            ->timeout(30)
            ->post($this->baseUrl.'/embeddings', [
                'model' => $this->embedModel,
                'input' => $texts,
            ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('OmniRoute embed failed: '.$resp->body());
        }

        if ($this->workspaceId && isset($resp->json()['usage']['prompt_tokens'])) {
            UsageMeter::track($this->workspaceId, 'ai_tokens_per_month', (int) $resp->json()['usage']['prompt_tokens']);
        }

        return array_column($resp->json()['data'] ?? [], 'embedding');
    }
}

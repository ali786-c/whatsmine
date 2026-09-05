<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Support\Facades\Http;
use App\Modules\Broadcasting\Models\UsageMeter;

class OllamaProvider implements LlmProviderInterface
{
    private string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly string $chatModel = 'qwen2:0.5b',
        private readonly string $embedModel = 'nomic-embed-text',
        private readonly ?int $workspaceId = null
    ) {
        $this->baseUrl = rtrim($baseUrl ?: 'http://127.0.0.1:11434', '/');
    }

    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $start = microtime(true);
        $model = $opts['model'] ?? $this->chatModel;

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => $opts['temperature'] ?? 0.7,
                'num_predict' => $opts['max_tokens'] ?? 1024,
            ]
        ];

        if (isset($opts['num_ctx'])) {
            $payload['options']['num_ctx'] = (int) $opts['num_ctx'];
        }

        if (isset($opts['keep_alive'])) {
            $payload['keep_alive'] = $opts['keep_alive'];
        }

        $resp = Http::retry(2, 500)->timeout(120)->post($this->baseUrl . '/api/chat', $payload);

        if (! $resp->successful()) {
            throw new \RuntimeException('Ollama chat failed: ' . $resp->body());
        }

        $json = $resp->json();
        $latency = (int) ((microtime(true) - $start) * 1000);

        $promptTokens = $json['prompt_eval_count'] ?? 0;
        $completionTokens = $json['eval_count'] ?? 0;

        if ($this->workspaceId) {
            UsageMeter::track($this->workspaceId, 'ai_tokens_per_month', $promptTokens + $completionTokens);
        }

        return new LlmResponse(
            content: $json['message']['content'] ?? '',
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            model: $json['model'] ?? $model,
            latencyMs: $latency,
        );
    }

    public function embed(array $texts): array
    {
        try {
            $resp = Http::retry(2, 500)->timeout(120)->post($this->baseUrl . '/api/embed', [
                'model' => $this->embedModel,
                'input' => $texts,
            ]);

            if ($resp->successful() && isset($resp->json()['embeddings'])) {
                if ($this->workspaceId && isset($resp->json()['prompt_eval_count'])) {
                    UsageMeter::track($this->workspaceId, 'ai_tokens_per_month', $resp->json()['prompt_eval_count']);
                }
                return $resp->json()['embeddings'];
            }
        } catch (\Exception $e) {
            // Ignore exception from primary endpoint to allow fallback
        }

        // Fallback for older versions of Ollama that don't support /api/embed or if it fails
        $embeddings = [];
        foreach ($texts as $text) {
            try {
                $respFallback = Http::retry(2, 500)->timeout(30)->post($this->baseUrl . '/api/embeddings', [
                    'model' => $this->embedModel,
                    'prompt' => $text,
                ]);

                if (! $respFallback->successful()) {
                    throw new \RuntimeException('Ollama embed failed: ' . $respFallback->body());
                }
                $embeddings[] = $respFallback->json()['embedding'];
            } catch (\Exception $e) {
                throw new \RuntimeException('AI Embedding failed: ' . $e->getMessage());
            }
        }

        return $embeddings;
    }
}

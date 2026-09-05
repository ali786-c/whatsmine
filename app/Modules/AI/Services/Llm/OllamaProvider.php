<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Support\Facades\Http;

class OllamaProvider implements LlmProviderInterface
{
    private string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly string $chatModel = 'qwen2:0.5b',
        private readonly string $embedModel = 'nomic-embed-text'
    ) {
        $this->baseUrl = rtrim($baseUrl ?: 'http://127.0.0.1:11434', '/');
    }

    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $start = microtime(true);
        $model = $opts['model'] ?? $this->chatModel;

        $resp = Http::retry(2, 500)->timeout(120)->post($this->baseUrl . '/api/chat', [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => $opts['temperature'] ?? 0.7,
                'num_predict' => $opts['max_tokens'] ?? 1024,
            ]
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('Ollama chat failed: ' . $resp->body());
        }

        $json = $resp->json();
        $latency = (int) ((microtime(true) - $start) * 1000);

        return new LlmResponse(
            content: $json['message']['content'] ?? '',
            promptTokens: $json['prompt_eval_count'] ?? 0,
            completionTokens: $json['eval_count'] ?? 0,
            model: $json['model'] ?? $model,
            latencyMs: $latency,
        );
    }

    public function embed(array $texts): array
    {
        $resp = Http::retry(2, 500)->timeout(120)->post($this->baseUrl . '/api/embed', [
            'model' => $this->embedModel,
            'input' => $texts,
        ]);

        if ($resp->successful() && isset($resp->json()['embeddings'])) {
            return $resp->json()['embeddings'];
        }

        // Fallback for older versions of Ollama that don't support /api/embed or if it fails
        $embeddings = [];
        foreach ($texts as $text) {
            $respFallback = Http::retry(2, 500)->timeout(30)->post($this->baseUrl . '/api/embeddings', [
                'model' => $this->embedModel,
                'prompt' => $text,
            ]);

            if (! $respFallback->successful()) {
                throw new \RuntimeException('Ollama embed failed: ' . $respFallback->body());
            }
            $embeddings[] = $respFallback->json()['embedding'];
        }

        return $embeddings;
    }
}

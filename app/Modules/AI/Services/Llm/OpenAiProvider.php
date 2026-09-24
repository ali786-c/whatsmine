<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Support\Facades\Http;

class OpenAiProvider implements LlmProviderInterface
{
    private const BASE = 'https://api.openai.com/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $chatModel = 'gpt-4o-mini',
        private readonly string $embedModel = 'text-embedding-3-small',
        private readonly ?string $organization = null,
    ) {}

    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $start = microtime(true);
        $headers = ['Authorization' => 'Bearer '.$this->apiKey];
        if ($this->organization) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        $resp = Http::withHeaders($headers)->retry(2, 500)->timeout(60)->post(self::BASE.'/chat/completions', [
            'model' => $opts['model'] ?? $this->chatModel,
            'messages' => $messages,
            'max_tokens' => $opts['max_tokens'] ?? 1024,
            'temperature' => $opts['temperature'] ?? 0.4,
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('OpenAI chat failed: '.$resp->body());
        }

        $json = $resp->json();
        $latency = (int) ((microtime(true) - $start) * 1000);

        $content = $json['choices'][0]['message']['content'] ?? '';

        // Dead-upstream detection (scraped OmniRoute upstreams behind an
        // OpenAI-compatible API): canned greeting loops and 200-wrapped errors.
        $guard = DeadUpstreamGuard::inspect($json, $content, DeadUpstreamGuard::lastUserMessage($messages));
        if ($guard !== null) {
            $upstreamModel = $json['model'] ?? $this->chatModel;
            throw new DeadUpstreamException("Dead upstream ({$upstreamModel}): {$guard}");
        }

        return new LlmResponse(
            content: $content,
            promptTokens: $json['usage']['prompt_tokens'] ?? 0,
            completionTokens: $json['usage']['completion_tokens'] ?? 0,
            model: $json['model'] ?? $this->chatModel,
            latencyMs: $latency,
        );
    }

    public function embed(array $texts): array
    {
        $resp = Http::withToken($this->apiKey)->retry(2, 500)->timeout(30)->post(self::BASE.'/embeddings', [
            'model' => $this->embedModel,
            'input' => $texts,
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('OpenAI embed failed: '.$resp->body());
        }

        return array_column($resp->json()['data'] ?? [], 'embedding');
    }
}

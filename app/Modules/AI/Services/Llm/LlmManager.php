<?php

namespace App\Modules\AI\Services\Llm;

use App\Models\Workspace;
use App\Models\SystemSetting;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\Integrations\Services\CredentialResolver;

class LlmManager
{
    /** Providers that support embeddings natively. */
    private const EMBED_CAPABLE = ['openai', 'gemini', 'ollama'];

    /** Resolve a provider for chat completions (all providers supported). */
    public static function forWorkspace(int $workspaceId): LlmProviderInterface
    {
        $config = AiProviderConfig::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->orderByRaw("FIELD(provider, 'openai', 'anthropic', 'gemini', 'ollama')")
            ->first();

        if ($config) {
            if ($config->provider === 'ollama' && SystemSetting::get('system_ai_enabled', 'false') === 'true') {
                return static::build('ollama', [
                    'api_key' => SystemSetting::get('system_ai_base_url', 'http://127.0.0.1:11434'),
                ], [
                    'chat' => SystemSetting::get('system_ai_default_model', 'qwen2:0.5b'),
                    'embed' => SystemSetting::get('system_ai_default_model', 'qwen2:0.5b'),
                ], $workspaceId);
            }
            if (! empty($config->credentials['api_key'] ?? '')) {
                return static::build($config->provider, $config->credentials ?? [], [
                    'chat' => $config->default_model_chat,
                    'embed' => $config->default_model_embed,
                ], $workspaceId);
            }
        }

        $workspace = app(Workspace::class)->find($workspaceId);
        foreach (['openai', 'anthropic', 'gemini', 'ollama'] as $provider) {
            $creds = CredentialResolver::for($workspace)->llm($provider);
            if ($creds) {
                return static::build($provider, $creds->toArray(), [], $workspaceId);
            }
        }

        throw new \RuntimeException('No AI provider configured for workspace '.$workspaceId);
    }

    /**
     * Resolve a provider for embeddings only.
     * Anthropic does not support embeddings — it is skipped automatically.
     * Falls back across OpenAI → Gemini in workspace config, then system defaults.
     */
    public static function forWorkspaceEmbed(int $workspaceId): LlmProviderInterface
    {
        // Workspace-level: prefer embed-capable providers, then fall back to any enabled one
        $configs = AiProviderConfig::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->orderByRaw("FIELD(provider, 'openai', 'gemini', 'ollama', 'anthropic')")
            ->get();

        foreach ($configs as $config) {
            if (! in_array($config->provider, self::EMBED_CAPABLE, true)) {
                continue;
            }
            if ($config->provider === 'ollama' && SystemSetting::get('system_ai_enabled', 'false') === 'true') {
                return static::build('ollama', [
                    'api_key' => SystemSetting::get('system_ai_base_url', 'http://127.0.0.1:11434'),
                ], [
                    'chat' => SystemSetting::get('system_ai_default_model', 'qwen2:0.5b'),
                    'embed' => SystemSetting::get('system_ai_default_model', 'qwen2:0.5b'),
                ], $workspaceId);
            }
            if (empty($config->credentials['api_key'] ?? '')) {
                continue;
            }
            return static::build($config->provider, $config->credentials ?? [], [
                'chat' => $config->default_model_chat,
                'embed' => $config->default_model_embed,
            ], $workspaceId);
        }

        // System-level fallback (embed-capable only)
        $workspace = app(Workspace::class)->find($workspaceId);
        foreach (self::EMBED_CAPABLE as $provider) {
            $creds = CredentialResolver::for($workspace)->llm($provider);
            if ($creds) {
                return static::build($provider, $creds->toArray(), [], $workspaceId);
            }
        }

        throw new \RuntimeException(
            'No embedding-capable AI provider (OpenAI or Gemini) configured for workspace '.$workspaceId.
            '. Anthropic does not support embeddings.'
        );
    }

    public static function build(string $provider, array $creds, array $models = [], ?int $workspaceId = null): LlmProviderInterface
    {
        return match ($provider) {
            'openai' => new OpenAiProvider(
                $creds['api_key'] ?? '',
                $models['chat'] ?? 'gpt-4o-mini',
                $models['embed'] ?? 'text-embedding-3-small',
                $creds['organization_id'] ?? null,
            ),
            'anthropic' => new AnthropicProvider($creds['api_key'] ?? '', $models['chat'] ?? 'claude-3-haiku-20240307'),
            'gemini' => new GeminiProvider($creds['api_key'] ?? '', $models['chat'] ?? 'gemini-1.5-flash', $models['embed'] ?? 'text-embedding-004'),
            'ollama' => new OllamaProvider($creds['api_key'] ?? 'http://127.0.0.1:11434', $models['chat'] ?? 'qwen2:0.5b', $models['embed'] ?? 'nomic-embed-text', $workspaceId),
            default => throw new \RuntimeException("Unknown LLM provider: {$provider}"),
        };
    }
}

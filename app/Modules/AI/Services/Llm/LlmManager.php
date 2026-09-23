<?php

namespace App\Modules\AI\Services\Llm;

use App\Models\Workspace;
use App\Models\SystemSetting;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\Integrations\Services\CredentialResolver;

class LlmManager
{
    /**
     * Providers that support embeddings natively.
     *
     * OmniRoute deliberately NOT included: the gateway only proxies whatever upstream
     * models are configured, and typical self-hosted instances ship 0 embedding models
     * (the embeddings call fails → KB retrieval silently dies → the bot answers from
     * general knowledge). Embeddings must come from a real provider (OpenAI/Gemini/Ollama).
     */
    private const EMBED_CAPABLE = ['openai', 'gemini', 'ollama'];

    /** System-level OmniRoute settings (Admin → Settings). */
    public const OMNIROUTE_KEYS = [
        'enabled' => 'system_omniroute_enabled',
        'base_url' => 'system_omniroute_base_url',
        'api_key' => 'system_omniroute_api_key',
        'model' => 'system_omniroute_default_model',
    ];

    /** Resolve a provider for chat completions (all providers supported). */
    public static function forWorkspace(int $workspaceId): LlmProviderInterface
    {
        $config = AiProviderConfig::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->orderByRaw("FIELD(provider, 'openai', 'anthropic', 'gemini', 'omniroute', 'ollama')")
            ->first();

        if ($config) {
            if ($config->provider === 'ollama' && SystemSetting::get('system_ai_enabled', 'false') === 'true') {
                return static::build('ollama', [
                    'api_key' => SystemSetting::get('system_ai_base_url', 'http://127.0.0.1:11434'),
                ], [
                    'chat' => SystemSetting::get('system_ai_default_model', 'qwen2:0.5b'),
                    'embed' => 'nomic-embed-text',
                ], $workspaceId);
            }
            // Workspace enabled OmniRoute: serve via the admin-configured system gateway
            // (clients never hold gateway credentials — usage is metered per workspace)
            if ($config->provider === 'omniroute' && ($omni = static::systemOmniroute($workspaceId))) {
                return $omni;
            }
            if (! empty($config->credentials['api_key'] ?? '')) {
                return static::build($config->provider, $config->credentials ?? [], [
                    'chat' => $config->default_model_chat,
                    'embed' => $config->default_model_embed,
                ], $workspaceId);
            }
        }

        $workspace = app(Workspace::class)->find($workspaceId);
        foreach (['openai', 'anthropic', 'gemini'] as $provider) {
            $creds = CredentialResolver::for($workspace)->llm($provider);
            if ($creds) {
                return static::build($provider, $creds->toArray(), [], $workspaceId);
            }
        }

        // System-level OmniRoute gateway (Admin → Settings → AI Providers)
        if ($omni = static::systemOmniroute($workspaceId)) {
            return $omni;
        }

        $systemAi = CredentialResolver::for($workspace)->llm('ollama');
        if ($systemAi) {
            return static::build('ollama', $systemAi->toArray(), [], $workspaceId);
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
        // Workspace-level: prefer embed-capable providers, skip OmniRoute/Anthropic
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
                    'embed' => 'nomic-embed-text',
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
            'omniroute' => new OmniRouteProvider(
                $creds['api_key'] ?? '',
                $creds['base_url'] ?? '',
                $models['chat'] ?? 'auto/chat',
                $models['embed'] ?? 'text-embedding-3-small',
                $workspaceId,
            ),
            default => throw new \RuntimeException("Unknown LLM provider: {$provider}"),
        };
    }

    /** Build the admin-configured system-level OmniRoute gateway, if enabled and complete. */
    public static function systemOmniroute(?int $workspaceId = null): ?OmniRouteProvider
    {
        if (SystemSetting::get(self::OMNIROUTE_KEYS['enabled'], 'false') !== 'true') {
            return null;
        }

        $baseUrl = trim((string) SystemSetting::get(self::OMNIROUTE_KEYS['base_url'], ''));
        $apiKey = trim((string) SystemSetting::get(self::OMNIROUTE_KEYS['api_key'], ''));

        if ($baseUrl === '' || $apiKey === '') {
            return null;
        }

        return new OmniRouteProvider(
            $apiKey,
            $baseUrl,
            (string) (SystemSetting::get(self::OMNIROUTE_KEYS['model'], '') ?: 'auto/chat'),
            workspaceId: $workspaceId,
        );
    }
}

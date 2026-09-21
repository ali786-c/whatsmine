<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\Llm\OmniRouteProvider;
use App\Models\Workspace;
use App\Modules\Broadcasting\Models\UsageMeter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Modules\AI\Services\Llm\LlmManager;

class AiProviderController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $configs = AiProviderConfig::where('workspace_id', $workspaceId)->get()->keyBy('provider');

        $providers = ['openai', 'anthropic', 'gemini', 'omniroute', 'ollama'];
        $list = collect($providers)->map(fn ($p) => [
            'provider' => $p,
            'enabled' => $configs->get($p)?->enabled ?? false,
            'configured' => ! empty($configs->get($p)?->credentials),
            'default_model_chat' => $configs->get($p)?->default_model_chat ?? '',
            'base_url' => $p === 'omniroute' ? ($configs->get($p)?->credentials['base_url'] ?? '') : null,
        ]);

        $workspace = Workspace::with('client')->find($workspaceId);
        $plan = $workspace?->client?->activePlan();
        $aiCreditsLimit = $plan?->limits['ai_tokens_per_month'] ?? null;
        $aiCreditsUsed = UsageMeter::current($workspaceId, 'ai_tokens_per_month');

        return Inertia::render('AI/Providers/Index', [
            'providers' => $list,
            'creditsLimit' => $aiCreditsLimit,
            'creditsUsed' => $aiCreditsUsed,
        ]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, ['openai', 'anthropic', 'gemini', 'omniroute', 'ollama'], true), 404);
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:512'],
            'base_url' => ['nullable', 'string', 'max:512'],
            'default_model_chat' => ['nullable', 'string', 'max:191'],
            'default_model_embed' => ['nullable', 'string', 'max:191'],
            'enabled' => ['boolean'],
        ]);

        $config = AiProviderConfig::firstOrNew(['workspace_id' => $workspaceId, 'provider' => $provider]);
        $creds = $config->credentials ?? [];

        if (! empty($validated['api_key']) && ! preg_match('/^•+/', $validated['api_key'])) {
            $creds['api_key'] = $validated['api_key'];
        }
        if ($provider === 'omniroute' && ! empty($validated['base_url'])) {
            $creds['base_url'] = rtrim(trim($validated['base_url']), '/');
        }

        $config->fill([
            'credentials' => $creds,
            'default_model_chat' => $validated['default_model_chat'] ?? $config->default_model_chat,
            'default_model_embed' => $validated['default_model_embed'] ?? $config->default_model_embed,
            'enabled' => (bool) $validated['enabled'],
        ])->save();

        return back()->with('success', ucfirst($provider).' configuration saved.');
    }

    /**
     * Fetch available models from an OpenAI-compatible gateway (OmniRoute).
     * Accepts stored credentials, or inline credentials for a quick test.
     */
    public function omnirouteModels(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'base_url' => ['nullable', 'string', 'max:512'],
            'api_key' => ['nullable', 'string', 'max:512'],
        ]);

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $config = AiProviderConfig::where('workspace_id', $workspaceId)->where('provider', 'omniroute')->first();

        $baseUrl = filled($validated['base_url'] ?? null) ? rtrim(trim($validated['base_url']), '/') : ($config?->credentials['base_url'] ?? '');
        $apiKey = filled($validated['api_key'] ?? null) && ! preg_match('/^•+/', $validated['api_key'])
            ? $validated['api_key']
            : ($config?->credentials['api_key'] ?? '');

        // Fall back to the admin-configured system gateway
        if ($baseUrl === '' || $apiKey === '') {
            $baseUrl = $baseUrl ?: trim((string) \App\Models\SystemSetting::get(LlmManager::OMNIROUTE_KEYS['base_url'], ''));
            $apiKey = $apiKey ?: trim((string) \App\Models\SystemSetting::get(LlmManager::OMNIROUTE_KEYS['api_key'], ''));
        }

        if ($baseUrl === '' || $apiKey === '') {
            return response()->json(['error' => 'OmniRoute base URL and API key are required.'], 422);
        }

        try {
            return response()->json(['models' => OmniRouteProvider::fetchModels($apiKey, $baseUrl)]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not reach OmniRoute: '.$e->getMessage()], 502);
        }
    }
}

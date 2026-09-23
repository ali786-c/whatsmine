<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\StorageManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SystemSettingsController extends Controller
{
    public function index(): Response
    {
        $generalKeys = ['app_name', 'app_tagline', 'support_email', 'primary_color'];

        $general = [];
        foreach ($generalKeys as $key) {
            $general[$key] = SystemSetting::get($key, '');
        }

        $logoPath    = SystemSetting::get('app_logo_path');
        $faviconPath = SystemSetting::get('app_favicon_path');

        $logoDisk    = SystemSetting::get('app_logo_disk', 'public');
        $faviconDisk = SystemSetting::get('app_favicon_disk', 'public');
        $sm = app(StorageManager::class);
        $sm->ensureDiskReady($logoDisk);
        $sm->ensureDiskReady($faviconDisk);
        $general['logo_url']    = $logoPath    ? Storage::disk($logoDisk)->url($logoPath)       : null;
        $general['favicon_url'] = $faviconPath ? Storage::disk($faviconDisk)->url($faviconPath) : null;

        $advanced = SystemSetting::orderBy('group')
            ->orderBy('key')
            ->whereNotIn('key', array_merge($generalKeys, ['app_logo_path', 'app_favicon_path']))
            ->get()
            ->map(fn ($s) => [
                'id'        => $s->id,
                'key'       => $s->key,
                'value'     => $s->is_secret
                    ? (strlen($s->attributes['value'] ?? '') > 0 ? '••••••••' : '')
                    : ($s->attributes['value'] ?? ''),
                'is_secret' => $s->is_secret,
                'group'     => $s->group,
            ]);

        $byGroup = $advanced->groupBy('group')->map->values();

        $firebase = [
            'enabled'    => SystemSetting::get('firebase_enabled', 'false') === 'true',
            'apiKey'     => SystemSetting::get('firebase_api_key', ''),
            'authDomain' => SystemSetting::get('firebase_auth_domain', ''),
            'projectId'  => SystemSetting::get('firebase_project_id', ''),
            'appId'      => SystemSetting::get('firebase_app_id', ''),
        ];

        $systemAi = [
            'enabled'           => SystemSetting::get('system_ai_enabled', 'false') === 'true',
            'baseUrl'           => SystemSetting::get('system_ai_base_url', 'http://127.0.0.1:11434'),
            'defaultModel'      => SystemSetting::get('system_ai_default_model', 'qwen2:0.5b'),
            'globalRules'       => SystemSetting::get('system_ai_global_rules', ''),
            'defaultTemperature' => (float) SystemSetting::get('system_ai_default_temperature', '0.3'),
        ];

        $omniKeys = \App\Modules\AI\Services\Llm\LlmManager::OMNIROUTE_KEYS;
        $omniroute = [
            'enabled'      => SystemSetting::get($omniKeys['enabled'], 'false') === 'true',
            'baseUrl'      => SystemSetting::get($omniKeys['base_url'], ''),
            'hasApiKey'    => filled(SystemSetting::get($omniKeys['api_key'], '')),
            'defaultModel' => SystemSetting::get($omniKeys['model'], ''),
        ];

        return Inertia::render('Admin/Settings/Index', [
            'general'         => $general,
            'settingsByGroup' => $byGroup,
            'firebase'        => $firebase,
            'systemAi'        => $systemAi,
            'omniroute'       => $omniroute,
        ]);
    }

    public function updateGeneral(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_name'      => ['nullable', 'string', 'max:128'],
            'app_tagline'   => ['nullable', 'string', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        foreach ($validated as $key => $value) {
            SystemSetting::set($key, $value, false, 'general');
        }

        return back()->with('success', __('General settings saved.'));
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
        ]);

        $this->deleteFile('app_logo_path', 'app_logo_disk');

        $sm   = app(StorageManager::class);
        $disk = $sm->diskName();
        $file = $request->file('logo');
        $path = $sm->prefixedPath('branding/logo-'.Str::uuid().'.'.$file->getClientOriginalExtension());
        $sm->disk()->putFileAs(dirname($path), $file, basename($path));

        SystemSetting::set('app_logo_path', $path, false, 'general');
        SystemSetting::set('app_logo_disk', $disk, false, 'general');

        return back()->with('success', __('Logo uploaded.'));
    }

    public function deleteLogo(): RedirectResponse
    {
        $this->deleteFile('app_logo_path', 'app_logo_disk');
        SystemSetting::whereIn('key', ['app_logo_path', 'app_logo_disk'])->delete();

        return back()->with('success', __('Logo removed.'));
    }

    public function uploadFavicon(Request $request): RedirectResponse
    {
        $request->validate([
            'favicon' => ['required', 'file', 'mimes:png,jpg,jpeg,gif,ico,svg,webp', 'max:512'],
        ]);

        $this->deleteFile('app_favicon_path', 'app_favicon_disk');

        $sm   = app(StorageManager::class);
        $disk = $sm->diskName();
        $file = $request->file('favicon');
        $path = $sm->prefixedPath('branding/favicon-'.Str::uuid().'.'.$file->getClientOriginalExtension());
        $sm->disk()->putFileAs(dirname($path), $file, basename($path));

        SystemSetting::set('app_favicon_path', $path, false, 'general');
        SystemSetting::set('app_favicon_disk', $disk, false, 'general');

        return back()->with('success', __('Favicon uploaded.'));
    }

    public function deleteFavicon(): RedirectResponse
    {
        $this->deleteFile('app_favicon_path', 'app_favicon_disk');
        SystemSetting::whereIn('key', ['app_favicon_path', 'app_favicon_disk'])->delete();

        return back()->with('success', __('Favicon removed.'));
    }

    public function updateFirebase(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'firebase_enabled'     => ['required', 'in:true,false'],
            'firebase_api_key'     => ['nullable', 'string', 'max:255'],
            'firebase_auth_domain' => ['nullable', 'string', 'max:255'],
            'firebase_project_id'  => ['nullable', 'string', 'max:128'],
            'firebase_app_id'      => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($validated as $key => $value) {
            SystemSetting::set($key, $value ?? '', false, 'firebase');
        }

        return back()->with('success', __('Firebase settings saved.'));
    }

    public function updateSystemAi(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'system_ai_enabled'            => ['required', 'in:true,false'],
            'system_ai_base_url'           => ['nullable', 'url', 'max:255'],
            'system_ai_default_model'      => ['nullable', 'string', 'max:128'],
            'system_ai_global_rules'       => ['nullable', 'string', 'max:8192'],
            'system_ai_default_temperature' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        foreach ($validated as $key => $value) {
            SystemSetting::set($key, $value ?? '', false, 'system_ai');
        }

        return back()->with('success', __('System AI settings saved.'));
    }

    public function updateOmniroute(Request $request): RedirectResponse
    {
        $keys = \App\Modules\AI\Services\Llm\LlmManager::OMNIROUTE_KEYS;

        $validated = $request->validate([
            'system_omniroute_enabled'       => ['required', 'in:true,false'],
            'system_omniroute_base_url'      => ['nullable', 'string', 'max:512'],
            'system_omniroute_api_key'       => ['nullable', 'string', 'max:512'],
            'system_omniroute_default_model' => ['nullable', 'string', 'max:191'],
        ]);

        foreach ($validated as $key => $value) {
            if ($key === $keys['api_key']) {
                // Bullets mean "unchanged" — never overwrite the stored secret with masked value
                if (empty($value) || preg_match('/^•+$/', $value)) {
                    continue;
                }
                SystemSetting::set($key, $value, true, 'system_omniroute');
                continue;
            }

            SystemSetting::set($key, $key === $keys['base_url'] ? rtrim(trim((string) $value), '/') : ($value ?? ''), false, 'system_omniroute');
        }

        return back()->with('success', __('OmniRoute settings saved.'));
    }

    /** Fetch models from the configured OmniRoute gateway (admin-side test). */
    public function omnirouteModels(Request $request): \Illuminate\Http\JsonResponse
    {
        $keys = \App\Modules\AI\Services\Llm\LlmManager::OMNIROUTE_KEYS;

        $validated = $request->validate([
            'base_url' => ['nullable', 'string', 'max:512'],
            'api_key'  => ['nullable', 'string', 'max:512'],
        ]);

        $baseUrl = filled($validated['base_url'] ?? null) ? rtrim(trim($validated['base_url']), '/') : (string) SystemSetting::get($keys['base_url'], '');
        $apiKey = filled($validated['api_key'] ?? null) && ! preg_match('/^•+$/', $validated['api_key'])
            ? $validated['api_key']
            : (string) SystemSetting::get($keys['api_key'], '');

        if ($baseUrl === '' || $apiKey === '') {
            return response()->json(['error' => 'OmniRoute base URL and API key are required.'], 422);
        }

        try {
            return response()->json(['models' => \App\Modules\AI\Services\Llm\OmniRouteProvider::fetchModels($apiKey, $baseUrl)]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not reach OmniRoute: '.$e->getMessage()], 502);
        }
    }

    /**
     * Live-probe an OmniRoute model: send a tiny chat completion and report what
     * actually answered (upstream model), the latency, and any error. Catches
     * dead/rate-limited/weak models at config time instead of on live chats.
     */
    public function omnirouteTestModel(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'base_url' => ['nullable', 'string', 'max:512'],
            'api_key'  => ['nullable', 'string', 'max:512'],
            'model'    => ['required', 'string', 'max:191'],
        ]);

        $keys = \App\Modules\AI\Services\Llm\LlmManager::OMNIROUTE_KEYS;
        $baseUrl = filled($validated['base_url'] ?? null) ? rtrim(trim($validated['base_url']), '/') : (string) SystemSetting::get($keys['base_url'], '');
        $apiKey = filled($validated['api_key'] ?? null) && ! preg_match('/^•+$/', $validated['api_key'])
            ? $validated['api_key']
            : (string) SystemSetting::get($keys['api_key'], '');

        if ($baseUrl === '' || $apiKey === '') {
            return response()->json(['error' => 'OmniRoute base URL and API key are required.'], 422);
        }

        $start = microtime(true);
        try {
            $resp = \Illuminate\Support\Facades\Http::withToken($apiKey)
                ->timeout(45)
                ->post($baseUrl.'/chat/completions', [
                    'model' => $validated['model'],
                    'messages' => [
                        ['role' => 'user', 'content' => 'Reply with exactly: OK'],
                    ],
                    'max_tokens' => 20,
                    'temperature' => 0,
                    'stream' => false,
                ]);

            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            if (! $resp->successful()) {
                $body = $resp->json();
                $msg = $body['error']['message'] ?? mb_substr($resp->body(), 0, 300);

                return response()->json(['ok' => false, 'error' => $msg], 200);
            }

            $json = $resp->json();
            $content = trim((string) ($json['choices'][0]['message']['content'] ?? ''));

            return response()->json([
                'ok' => $content !== '',
                'upstream_model' => $json['model'] ?? null,
                'reply' => mb_substr($content, 0, 200),
                'latency_ms' => $latencyMs,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'settings'             => ['required', 'array'],
            'settings.*.key'       => ['required', 'string', 'max:128'],
            'settings.*.value'     => ['nullable', 'string'],
            'settings.*.is_secret' => ['boolean'],
            'settings.*.group'     => ['nullable', 'string', 'max:64'],
        ]);

        foreach ($validated['settings'] as $s) {
            $model            = SystemSetting::firstOrNew(['key' => $s['key']]);
            $model->is_secret = $s['is_secret'] ?? false;
            $model->group     = $s['group'] ?? null;
            $value            = $s['value'] ?? null;
            if ($value !== null && $value !== '' && ! ($model->is_secret && preg_match('/^•+$/', (string) $value))) {
                $model->value = $value;
            }
            $model->save();
        }

        return back()->with('success', __('Settings saved.'));
    }

    private function deleteFile(string $pathKey, string $diskKey): void
    {
        $existing = SystemSetting::get($pathKey);
        $disk     = SystemSetting::get($diskKey, 'public');
        if ($existing) {
            app(StorageManager::class)->ensureDiskReady($disk);
            Storage::disk($disk)->delete($existing);
        }
    }
}

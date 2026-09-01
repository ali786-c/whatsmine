<?php

namespace App\Modules\Integrations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrations\Models\IntegrationAuditLog;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\ConnectionTester;
use App\Services\StorageManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationConfigController extends Controller
{
    public function index(): Response
    {
        $configs = IntegrationConfig::whereIn('provider', IntegrationConfig::PROVIDERS)->get()->keyBy('provider');

        $grouped = [];
        foreach (IntegrationConfig::PROVIDERS as $provider) {
            $config = $configs->get($provider);
            $category = IntegrationConfig::CATEGORIES[$provider] ?? 'Other';
            $grouped[$category][] = [
                'provider' => $provider,
                'label' => IntegrationConfig::LABELS[$provider] ?? $provider,
                'category' => $category,
                'enabled' => $config?->enabled ?? false,
                'is_default' => $config?->is_default ?? false,
                'mode' => $config?->mode ?? 'live',
                'configured' => $config?->isConfigured() ?? false,
                'last_test_status' => $config?->last_test_status ?? 'untested',
                'last_test_message' => $config?->last_test_message,
                'last_tested_at' => $config?->last_tested_at?->toISOString(),
            ];
        }

        return Inertia::render('Admin/Integrations/Index', [
            'grouped' => $grouped,
        ]);
    }

    public function edit(string $provider): Response
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        $config = IntegrationConfig::forProvider($provider) ?? new IntegrationConfig([
            'provider' => $provider,
            'label' => IntegrationConfig::LABELS[$provider] ?? $provider,
            'mode' => 'live',
            'enabled' => false,
        ]);

        return Inertia::render('Admin/Integrations/Edit', [
            'provider' => $provider,
            'label' => IntegrationConfig::LABELS[$provider] ?? $provider,
            'category' => IntegrationConfig::CATEGORIES[$provider] ?? 'Other',
            'fields' => IntegrationConfig::FIELDS[$provider] ?? [],
            // OAuth redirect/callback URL the admin must register in the platform's app settings.
            'callbackUrl' => match ($provider) {
                'oauth_shopify' => route('client.ecommerce.oauth.shopify.callback'),
                'oauth_bigcommerce' => route('client.ecommerce.oauth.bigcommerce.callback'),
                default => null,
            },
            'config' => [
                'enabled' => $config->enabled ?? false,
                'mode' => $config->mode ?? 'live',
                'last_test_status' => $config->last_test_status ?? 'untested',
                'last_test_message' => $config->last_test_message,
                'last_tested_at' => $config->last_tested_at?->toISOString(),
                'credentials' => $config->exists ? $config->maskedCredentials() : [],
            ],
        ]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        // ── STEP 1: Build validation rules ──────────────────────────────────
        $fields = IntegrationConfig::FIELDS[$provider] ?? [];
        $rules = ['enabled' => ['required', 'boolean'], 'mode' => ['required', 'in:test,live']];
        foreach ($fields as $f) {
            $rules['credentials.'.$f['key']] = [$f['required'] ? 'nullable' : 'nullable', 'string', 'max:1024'];
        }

        $validated = $request->validate($rules);

        $log = \Illuminate\Support\Facades\Log::build([
            'driver' => 'single',
            'path'   => storage_path('logs/credentials.log'),
        ]);

        // ── STEP 2: Log raw incoming values from frontend ────────────────────
        $incoming = $validated['credentials'] ?? [];
        $incomingDebug = [];
        foreach ($incoming as $k => $v) {
            if ($v === null)       $incomingDebug[$k] = '[NULL]';
            elseif ($v === '')     $incomingDebug[$k] = '[EMPTY STRING]';
            elseif (preg_match('/^•/', (string)$v)) $incomingDebug[$k] = '[MASKED: '.mb_strlen($v).' chars]';
            else                   $incomingDebug[$k] = '[VALUE: '.mb_substr($v, 0, 6).'...]';
        }
        $log->info("[$provider] INCOMING from frontend", $incomingDebug);

        $config = IntegrationConfig::firstOrNew(['provider' => $provider, 'mode' => $validated['mode']]);

        // ── STEP 3: Log existing DB credentials ──────────────────────────────
        $existing = $config->credentials ?? [];
        $existingDebug = [];
        foreach ($existing as $k => $v) {
            $existingDebug[$k] = ($v === null || $v === '') ? '[EMPTY]' : '[HAS VALUE: '.mb_substr((string)$v, 0, 6).'...]';
        }
        $log->info("[$provider] EXISTING in DB", $existingDebug);

        // ── STEP 4: Merge logic with per-key decision logging ─────────────────
        $merged = $existing;
        $changedKeys = [];
        $mergeDecisions = [];

        foreach ($incoming as $k => $v) {
            // Laravel's ConvertEmptyStringsToNull middleware converts "" → null.
            // Use $request->has() to tell apart "user submitted empty" vs "field absent".
            $fieldWasSubmitted = $request->has('credentials.' . $k);

            if ($v === null && ! $fieldWasSubmitted) {
                $mergeDecisions[$k] = 'SKIP (field not present in request at all)';
                continue;
            }

            // null here means user cleared the field (empty string → null by middleware)
            if ($v === null) {
                $v = '';
            }

            if (preg_match('/^[\x{2022}•]+$/u', (string) $v) || (string) $v === '••••••••••••') {
                $mergeDecisions[$k] = 'SKIP (masked placeholder - keep existing)';
                continue;
            }

            $old = $existing[$k] ?? null;
            if ($v === '') {
                unset($merged[$k]);
                $mergeDecisions[$k] = 'CLEARED (unset from array)';
                if ($old !== null && $old !== '') {
                    $changedKeys[] = $k;
                }
            } else {
                $merged[$k] = $v;
                $mergeDecisions[$k] = 'SET to new value';
                if ($v !== $old) {
                    $changedKeys[] = $k;
                }
            }
        }

        $log->info("[$provider] MERGE DECISIONS", $mergeDecisions);

        // ── STEP 5: Log what will be saved ────────────────────────────────────
        $mergedDebug = [];
        foreach ($merged as $k => $v) {
            $mergedDebug[$k] = ($v === null || $v === '') ? '[EMPTY/NULL]' : '[HAS VALUE]';
        }
        $log->info("[$provider] FINAL merged (will be saved to DB)", $mergedDebug);
        $log->info("[$provider] Changed keys", $changedKeys);

        $wasEnabled = $config->enabled ?? false;
        $config->fill([
            'label'               => IntegrationConfig::LABELS[$provider] ?? $provider,
            'enabled'             => (bool) $validated['enabled'],
            'mode'                => $validated['mode'],
            'credentials'         => $merged,
            'updated_by_admin_id' => auth('admin')->id(),
        ])->save();

        // ── STEP 6: Log what maskedCredentials() returns after save ───────────
        $config->refresh();
        $afterSave = $config->maskedCredentials();
        $afterSaveDebug = [];
        foreach ($afterSave as $k => $v) {
            $afterSaveDebug[$k] = ($v === '') ? '[EMPTY - will show blank]' : (preg_match('/^•/', $v) ? '[MASKED]' : '[PLAIN: '.mb_substr($v,0,6).'...]');
        }
        $log->info("[$provider] maskedCredentials() AFTER SAVE (what frontend will see)", $afterSaveDebug);

        $this->auditLog($request, $config, $config->wasRecentlyCreated ? 'create' : 'update', $changedKeys);
        if ($wasEnabled !== $config->enabled) {
            $this->auditLog($request, $config, $config->enabled ? 'enable' : 'disable', []);
        }

        if (str_starts_with($provider, 'storage_')) {
            app(StorageManager::class)->clearCache();
        }

        return redirect()->route('admin.integrations.edit', $provider)
            ->with('success', 'Integration saved.');
    }


    public function test(Request $request, string $provider): RedirectResponse|JsonResponse
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        $config = IntegrationConfig::forProvider($provider);
        if (! $config) {
            return response()->json(['ok' => false, 'message' => 'Not configured yet.']);
        }

        $result = app(ConnectionTester::class)->test($config);
        $this->auditLog($request, $config, 'test', []);

        if ($request->wantsJson()) {
            return response()->json($result);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function toggle(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        $config = IntegrationConfig::forProvider($provider);
        if (! $config) {
            return back()->with('error', 'Configure credentials before enabling.');
        }

        $updates = ['enabled' => ! $config->enabled];
        // If disabling a storage that was the default, clear its default flag
        if ($config->enabled && ($config->is_default ?? false) && str_starts_with($provider, 'storage_')) {
            $updates['is_default'] = false;
        }
        $config->update($updates);
        $this->auditLog($request, $config, $config->enabled ? 'enable' : 'disable', []);

        if (str_starts_with($provider, 'storage_')) {
            app(StorageManager::class)->clearCache();
        }

        return back()->with('success', 'Integration '.($config->enabled ? 'enabled' : 'disabled').'.');
    }

    public function setDefault(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, IntegrationConfig::STORAGE_PROVIDERS, true), 404);

        $config = IntegrationConfig::forProvider($provider);
        if (! $config || ! $config->enabled) {
            return back()->with('error', 'Only an enabled storage provider can be set as default.');
        }

        // Clear is_default on all other storage providers
        IntegrationConfig::whereIn('provider', IntegrationConfig::STORAGE_PROVIDERS)
            ->where('provider', '!=', $provider)
            ->update(['is_default' => false]);

        $config->update(['is_default' => true]);
        $this->auditLog($request, $config, 'update', ['is_default']);

        app(StorageManager::class)->clearCache();

        return back()->with('success', IntegrationConfig::LABELS[$provider].' set as default storage.');
    }

    public function rotate(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        $config = IntegrationConfig::forProvider($provider);
        if (! $config) {
            return back()->with('error', 'Not configured.');
        }

        $secret = bin2hex(random_bytes(32));
        $config->update(['webhook_secret' => $secret, 'updated_by_admin_id' => auth('admin')->id()]);
        $this->auditLog($request, $config, 'rotate', ['webhook_secret']);

        return back()->with('success', 'Webhook secret rotated.');
    }

    public function auditLogIndex(Request $request): Response
    {
        $logs = IntegrationAuditLog::with('admin')
            ->latest('created_at')
            ->paginate(50);

        return Inertia::render('Admin/Integrations/AuditLog', ['logs' => $logs]);
    }

    private function auditLog(Request $request, IntegrationConfig $config, string $action, array $changedKeys): void
    {
        IntegrationAuditLog::create([
            'admin_user_id' => auth('admin')->id(),
            'integration_config_id' => $config->id,
            'provider' => $config->provider,
            'action' => $action,
            'diff_json' => $changedKeys,
            'ip' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 512),
        ]);
    }
}

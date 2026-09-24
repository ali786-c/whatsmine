<?php

namespace App\Modules\AI\Services;

use App\Models\SystemSetting;
use App\Modules\AI\Services\Llm\DeadUpstreamGuard;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\OmniRouteProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * One-shot AI pipeline diagnostic.
 *
 * Answers, with evidence: "Why is the bot replying with garbage?"
 *
 * Checks, in order:
 *  1. Which OmniRoute settings are actually stored in the DB (and is it enabled).
 *  2. Whether the deployed code contains the dead-upstream guards (older code
 *     on the VPS happily forwards canned greetings — the #1 hidden cause).
 *  3. Raw gateway chat call for the configured default model — the exact JSON
 *     body the bot would receive, printed verbatim.
 *  4. The same call with auto/chat for comparison.
 *  5. Guard verdict on each raw reply.
 *  6. A final diagnosis string naming the root cause.
 */
class AiDiagnostic
{
    /** Strings that must exist in this build for the dead-upstream defense to be active. */
    private const CODE_MARKERS = [
        'DeadUpstreamGuard::inspect' => 'dead-upstream guard wired in OmniRouteProvider',
        'canned_greeting_loop' => 'greeting-loop detection reason string',
        'llm.dead_upstream_retry' => 'gateway auto-retry log marker',
    ];

    private const CANARY = 'What products do you offer? Answer in one short sentence.';

    public function run(): array
    {
        $settings = $this->settings();
        $code = $this->codeMarkers();
        $probes = $this->probes($settings);

        return [
            'settings' => $settings,
            'code' => $code,
            'probes' => $probes,
            'diagnosis' => $this->diagnosis ?? $this->defaultDiagnosis($code, $probes),
        ];
    }

    private ?string $diagnosis = null;

    // ── 1. Stored settings ────────────────────────────────────────────────

    private function settings(): array
    {
        $key = trim((string) SystemSetting::get(LlmManager::OMNIROUTE_KEYS['api_key'], ''));
        $baseUrl = rtrim(trim((string) SystemSetting::get(LlmManager::OMNIROUTE_KEYS['base_url'], '')), '/');
        $model = trim((string) SystemSetting::get(LlmManager::OMNIROUTE_KEYS['model'], '')) ?: 'auto/chat';

        return [
            'enabled' => SystemSetting::get(LlmManager::OMNIROUTE_KEYS['enabled'], 'false') === 'true',
            'base_url' => $baseUrl,
            'api_key_set' => $key !== '',
            'api_key_preview' => $key !== '' ? Str::substr($key, 0, 8).'…'.Str::substr($key, -4) : null,
            'default_model' => $model,
            'model_is_recommended_combo' => str_starts_with($model, 'auto/'),
        ];
    }

    // ── 2. Deployed-code markers ──────────────────────────────────────────

    private function codeMarkers(): array
    {
        $omniProviderFile = (string) @file_get_contents(__DIR__.'/Llm/OmniRouteProvider.php');
        $gatewayFile = (string) @file_get_contents(__DIR__.'/LlmGateway.php');
        $guardFile = (string) @file_get_contents(__DIR__.'/Llm/DeadUpstreamGuard.php');

        $markers = [];
        foreach (self::CODE_MARKERS as $needle => $label) {
            $markers[$needle] = [
                'label' => $label,
                'present' => str_contains($omniProviderFile.$gatewayFile.$guardFile, $needle),
            ];
        }

        $allPresent = collect($markers)->every(fn ($m) => $m['present']);

        return [
            'defense_active' => $allPresent,
            'note' => $allPresent
                ? 'Deployed code contains the dead-upstream guards.'
                : 'DEPLOYED CODE IS OLD — dead-upstream guards are missing. Run: git pull && php artisan queue:restart',
            'markers' => $markers,
        ];
    }

    // ── 3. Live gateway probes ────────────────────────────────────────────

    private function probes(array $settings): array
    {
        if (! $settings['enabled']) {
            $this->diagnose('OmniRoute is DISABLED in settings. The bot is falling back to another provider or failing.');

            return [];
        }

        if ($settings['base_url'] === '' || ! $settings['api_key_set']) {
            $this->diagnose('OmniRoute base URL or API key is missing in settings.');

            return [];
        }

        $key = trim((string) SystemSetting::get(LlmManager::OMNIROUTE_KEYS['api_key'], ''));
        $probes = [];

        // Probe the configured default model first.
        $probes[] = $this->probe($key, $settings['base_url'], $settings['default_model'], 'configured default model');

        // Always probe auto/chat as a healthy baseline comparison.
        if ($settings['default_model'] !== 'auto/chat') {
            $probes[] = $this->probe($key, $settings['base_url'], 'auto/chat', 'auto/chat baseline');
        }

        return $probes;
    }

    /**
     * One raw chat call — stores the exact upstream JSON so nothing is hidden.
     */
    private function probe(string $key, string $baseUrl, string $model, string $label): array
    {
        $started = microtime(true);

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are HostingGram support. Answer from the provided context only.'],
                ['role' => 'user', 'content' => self::CANARY.' Context: We offer web hosting, domains and business email.'],
            ],
            'max_tokens' => 200,
            'temperature' => 0.3,
            'stream' => false,
        ];

        try {
            $resp = Http::withToken($key)->timeout(45)->post($baseUrl.'/chat/completions', $payload);
        } catch (\Throwable $e) {
            $this->diagnose("Gateway unreachable for [{$model}]: ".$e->getMessage());

            return ['label' => $label, 'model' => $model, 'ok' => false, 'error' => $e->getMessage()];
        }

        $latency = (int) ((microtime(true) - $started) * 1000);
        $status = $resp->status();
        $body = $resp->body();
        $json = json_decode($body, true);

        $content = $json['choices'][0]['message']['content'] ?? null;
        $upstreamModel = $json['model'] ?? null;
        $guardReason = is_array($json)
            ? DeadUpstreamGuard::inspect($json, $content, self::CANARY)
            : 'not_json_body';

        $ok = $status === 200 && is_string($content) && $guardReason === null;

        if (! $ok && $this->diagnosis === null) {
            if ($guardReason !== null) {
                $this->diagnose(
                    "[{$model}] is a DEAD UPSTREAM (guard: {$guardReason}). The bot sends its canned replies to customers. ".
                    'Fix: Admin → Settings → OmniRoute → Default Model = auto/chat (or another verified live model), then Save and php artisan queue:restart.'
                );
            } elseif ($status !== 200) {
                $this->diagnose(
                    "[{$model}] returned HTTP {$status}. Body preview in probe details. ".
                    'Fix: pick a model that passes the Test Model probe in Admin → Settings → OmniRoute.'
                );
            }
        }

        return [
            'label' => $label,
            'model' => $model,
            'http_status' => $status,
            'latency_ms' => $latency,
            'upstream_model' => $upstreamModel,
            'ok' => $ok,
            'guard_reason' => $guardReason,
            'reply_preview' => $content !== null ? Str::limit(trim((string) $content), 220) : null,
            'raw_body' => Str::limit($body, 900),
        ];
    }

    // ── 4. Diagnosis ──────────────────────────────────────────────────────

    private function diagnose(string $text): void
    {
        $this->diagnosis = $this->diagnosis ?? $text;
    }

    /** Verdict when nothing else fired: everything healthy, or old code on a healthy gateway. */
    private function defaultDiagnosis(array $code, array $probes): string
    {
        if ($probes !== [] && collect($probes)->contains(fn ($p) => ($p['ok'] ?? false) === true) && ! $code['defense_active']) {
            return 'Gateway and model are healthy, but the DEPLOYED CODE IS OLD (guards missing). '.
                'Old code forwards canned greetings to customers. Run: git pull && php artisan migrate && php artisan queue:restart';
        }

        if ($probes !== [] && collect($probes)->every(fn ($p) => ($p['ok'] ?? false) === true)) {
            return 'All checks passed — gateway reachable, model healthy, guards active. '.
                'If the bot still misbehaves, check the workspace-level provider config and the bot\'s own settings.';
        }

        return 'No probe ran (gateway disabled or credentials missing) — see settings section above.';
    }
}

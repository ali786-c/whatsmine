<?php

namespace App\Console\Commands;

use App\Modules\AI\Services\AiDiagnostic;
use Illuminate\Console\Command;

/**
 * One-shot AI root-cause diagnostic.
 *
 * Usage:  php artisan ai:diagnose
 *
 * Prints the stored OmniRoute settings, whether the deployed code contains the
 * dead-upstream guards, live gateway probes for the configured model and for
 * auto/chat, and a final diagnosis naming the root cause with the fix.
 */
class AiDiagnoseCommand extends Command
{
    protected $signature = 'ai:diagnose';

    protected $description = 'Diagnose the AI pipeline end-to-end (settings, deployed code, live gateway probes) and name the root cause of bad bot replies';

    public function handle(AiDiagnostic $diagnostic): int
    {
        $report = $diagnostic->run();

        $this->info('┌─ AI PIPELINE DIAGNOSTIC ─────────────────────────────');

        // Settings
        $s = $report['settings'];
        $this->line('│ Settings');
        $this->line('│   enabled:            '.($s['enabled'] ? 'yes' : 'NO  ← gateway off'));
        $this->line('│   base_url:           '.($s['base_url'] ?: '(missing)'));
        $this->line('│   api_key:            '.($s['api_key_preview'] ?? 'MISSING'));
        $this->line('│   default_model:      '.$s['default_model'].($s['model_is_recommended_combo'] ? '' : '  ← not an auto/* combo'));

        // Deployed code
        $c = $report['code'];
        $this->line('│ Deployed code');
        $this->line('│   guards active:      '.($c['defense_active'] ? 'yes' : 'NO  ← OLD CODE DEPLOYED'));
        if (! $c['defense_active']) {
            foreach ($c['markers'] as $needle => $m) {
                if (! $m['present']) {
                    $this->line("│     missing: {$needle}");
                }
            }
        }

        // Probes
        $this->line('│ Live gateway probes');
        if ($report['probes'] === []) {
            $this->line('│   (none — gateway disabled or credentials missing)');
        }
        foreach ($report['probes'] as $p) {
            $status = ($p['ok'] ?? false) ? 'OK' : 'FAIL';
            $this->line("│   [{$status}] {$p['model']}  ({$p['label']})");
            $this->line('│       http: '.($p['http_status'] ?? 'n/a').'  latency: '.($p['latency_ms'] ?? 'n/a')."ms  upstream: ".($p['upstream_model'] ?? 'n/a'));
            if (($p['guard_reason'] ?? null) !== null) {
                $this->line('│       GUARD: '.$p['guard_reason']);
            }
            if (($p['reply_preview'] ?? null) !== null) {
                $this->line('│       reply: '.preg_replace('/\s+/', ' ', $p['reply_preview']));
            }
            if (($p['error'] ?? null) !== null) {
                $this->line('│       error: '.$p['error']);
            }
            if (! ($p['ok'] ?? false) && ($p['raw_body'] ?? null) !== null) {
                $this->line('│       raw: '.preg_replace('/\s+/', ' ', $p['raw_body']));
            }
        }

        $this->line('│ Diagnosis');
        $this->line('│   '.$report['diagnosis']);
        $this->info('└──────────────────────────────────────────────────────');

        return self::SUCCESS;
    }
}

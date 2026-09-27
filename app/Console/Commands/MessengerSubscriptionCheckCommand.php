<?php

namespace App\Console\Commands;

use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Messenger page subscription doctor.
 *
 * The single most common "only one page shows messages" cause: the PAGE-level
 * webhook subscription (/me/subscribed_apps) is missing or lacks a field on
 * some pages. The app-level subscription is shared by all pages, but each
 * page must subscribe individually — embedded signup does it for the page you
 * signed up with; pages connected in a later batch often end up without it.
 *
 *   php artisan messenger:check-subscriptions              # diagnose all pages
 *   php artisan messenger:check-subscriptions --fix        # diagnose + repair
 *   php artisan messenger:check-subscriptions --account=12 # one page only
 */
class MessengerSubscriptionCheckCommand extends Command
{
    protected $signature = 'messenger:check-subscriptions
                            {--fix : Re-subscribe pages that are missing the required fields}
                            {--account= : ChannelAccount id (default: all messenger accounts)}';

    protected $description = 'Check (and optionally repair) per-page Messenger webhook subscriptions so every connected page receives messages';

    /** Fields every page must be subscribed for. */
    private const FIELDS = 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads,message_echoes';

    public function handle(): int
    {
        $query = ChannelAccount::where('channel', 'messenger')->where('status', 'active');
        if ($accountId = $this->option('account')) {
            $query->whereKey($accountId);
        }
        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->error('No connected Messenger accounts found.');

            return self::FAILURE;
        }

        $broken = 0;

        foreach ($accounts as $account) {
            $pageId = (string) ($account->meta_json['page_id'] ?? '');
            $label = sprintf('#%d %s', $account->id, $account->display_name);

            if ($pageId === '') {
                $this->warn("  [?] {$label}: no page_id in meta_json — reconnect this page.");
                $broken++;

                continue;
            }

            try {
                $token = $account->credentials['page_access_token'] ?? '';
            } catch (\Throwable $e) {
                $this->error("  [X] {$label}: credentials undecryptable ({$e->getMessage()}) — reconnect.");
                $broken++;

                continue;
            }

            if ($token === '') {
                $this->error("  [X] {$label}: no page_access_token — reconnect.");
                $broken++;

                continue;
            }

            // 1. What is ACTUALLY subscribed on Meta for this page?
            try {
                $check = Http::withToken($token)
                    ->timeout(15)
                    ->get("https://graph.facebook.com/v20.0/{$pageId}/subscribed_apps");
            } catch (\Throwable $e) {
                $this->error("  [X] {$label}: Graph request failed ({$e->getMessage()})");
                $broken++;

                continue;
            }

            if (! $check->successful()) {
                $this->error(sprintf(
                    '  [X] %s: subscription check failed (HTTP %d, code %s: %s)',
                    $label,
                    $check->status(),
                    $check->json('error.code') ?? '?',
                    $check->json('error.message') ?? 'unknown',
                ));
                $broken++;

                continue;
            }

            $apps = $check->json('data', []);
            $ourAppId = config('services.meta.app_id')
                ?: (\App\Modules\Integrations\Services\CredentialResolver::system()->meta()?->appId());

            $subscribedFields = [];
            foreach ($apps as $app) {
                // Each entry: {id: app_id, name, subscribed_fields: [...]}.
                if ($ourAppId === null || (string) ($app['id'] ?? '') === (string) $ourAppId) {
                    $subscribedFields = $app['subscribed_fields'] ?? [];
                    break;
                }
            }

            $required = ['messages', 'message_echoes', 'messaging_postbacks'];
            $missing = array_diff($required, $subscribedFields);

            if ($missing === []) {
                $this->info("  [OK] {$label} (page {$pageId}): subscribed for ".implode(', ', $subscribedFields));

                continue;
            }

            $broken++;
            $this->warn(sprintf(
                '  [!!] %s (page %s): MISSING fields [%s] — Meta will NOT deliver its messages to us.',
                $label,
                $pageId,
                implode(', ', $missing),
            ));

            if (! $this->option('fix')) {
                continue;
            }

            // 2. Repair: POST subscribed_apps with the full field set.
            $fix = Http::withToken($token)
                ->timeout(15)
                ->post("https://graph.facebook.com/v20.0/{$pageId}/subscribed_apps", [
                    'subscribed_fields' => self::FIELDS,
                ]);

            if (! $fix->successful()) {
                $this->error(sprintf(
                    '  [X] %s: RE-SUBSCRIBE FAILED (HTTP %d, code %s: %s)',
                    $label,
                    $fix->status(),
                    $fix->json('error.code') ?? '?',
                    $fix->json('error.message') ?? 'unknown',
                ));
                Log::warning('messenger:check-subscriptions repair failed', [
                    'channel_account_id' => $account->id,
                    'page_id' => $pageId,
                    'response' => $fix->json(),
                ]);

                continue;
            }

            // 3. Verify the repair actually stuck.
            $verify = Http::withToken($token)
                ->timeout(15)
                ->get("https://graph.facebook.com/v20.0/{$pageId}/subscribed_apps");
            $after = collect($verify->json('data', []))
                ->firstWhere('id', $ourAppId)['subscribed_fields'] ?? [];

            $stillMissing = array_diff($required, $after);

            if ($stillMissing === []) {
                $this->info("  [FIXED] {$label}: now subscribed for ".implode(', ', $after));
            } else {
                $this->error("  [X] {$label}: still missing [" . implode(', ', $stillMissing) . '] after repair — check page token validity / page admin permissions.');
            }
        }

        if ($broken === 0) {
            $this->info('All Messenger pages are correctly subscribed.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($this->option('fix')
            ? "Done — {$broken} issue(s) processed. Send a test message to each page to confirm delivery."
            : "Run with --fix to re-subscribe the flagged page(s).");

        return self::SUCCESS;
    }
}

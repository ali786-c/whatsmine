<?php

namespace App\Console\Commands;

use App\Modules\Instagram\Models\InstagramAccount;
use App\Modules\Instagram\Services\InstagramGraphClient;
use App\Modules\Shared\Services\MetaWebhookRegistrar;
use Illuminate\Console\Command;

/**
 * (Re-)register the `instagram` webhook subscription against Meta on BOTH apps
 * (the classic Meta app AND the Instagram app) AND subscribe every connected
 * IG-Login account's fields on graph.instagram.com.
 *
 * Use when instagram:diagnose reports a missing webhook subscription or an
 * account without field subscriptions. Idempotent: always writes the full
 * expected callback + field set through the shared registrar.
 *
 * NOTE: the legacy "re-subscribe Facebook Page to messages" step is gone —
 * Instagram connects exclusively via Business Login for Instagram now.
 */
class InstagramRegisterWebhookCommand extends Command
{
    protected $signature = 'instagram:register-webhook';

    protected $description = '(Re-)register the instagram webhook subscription with Meta (both apps + IG account field subscription)';

    public function handle(): int
    {
        $this->info('Registering the instagram webhook object with Meta...');

        MetaWebhookRegistrar::registerInstagramObject();

        $subscription = MetaWebhookRegistrar::verifyInstagramObject();

        if ($subscription === null) {
            $this->error('Registration did not stick — no instagram subscription found at Meta.');
            $this->line('Check storage/logs/laravel.log for "meta_webhook_registrar" entries.');
            $this->line('Most common cause: verify_token missing in Admin → Integrations → Meta App.');

            return Command::FAILURE;
        }

        $this->info('Instagram subscription confirmed at Meta:');
        $this->line('  callback_url: '.($subscription['callback_url'] ?? '(none)'));
        $this->line('  fields:       '.implode(', ', MetaWebhookRegistrar::normalizeFields($subscription['fields'] ?? [])));

        // Instagram-app (Business Login for Instagram) object — the one that
        // actually delivers events for IG-Login accounts.
        $igResult = MetaWebhookRegistrar::registerInstagramAppObject();

        if ($igResult === null) {
            $this->warn('Instagram app subscription SKIPPED — IG App ID/Secret not configured (Admin → Integrations → Meta App).');
        } elseif (($igResult['ok'] ?? false) === true) {
            $this->info('Instagram app subscription confirmed: callback '.($igResult['callback_url'] ?? '?'));
        } else {
            $this->error('Instagram app subscription FAILED: '.(string) ($igResult['error'] ?? 'unknown error'));
            $this->line('  Meta rejected the API registration for this Instagram app (a known Meta-side');
            $this->line('  rejection on some IG apps). Register it MANUALLY once in the dashboard:');
            $this->line('  1. developers.facebook.com → your Instagram app (the one used at connect) → Webhooks');
            $this->line('  2. Object: "instagram" → Configure');
            $this->line('  3. Callback URL:  '.url('/webhooks/instagram'));
            $this->line('  4. Verify token:  the SAME Webhook Verify Token saved in Admin → Integrations → Meta App');
            $this->line('  5. Fields:        '.str_replace(',', ', ', MetaWebhookRegistrar::INSTAGRAM_APP_FIELDS));
            $this->line('  6. Verify and Save — the dashboard test must return the challenge.');
        }

        $this->subscribeAccounts();

        $this->line('');
        $this->line('Next: send a DM from another Instagram account, then re-run php artisan instagram:diagnose.');

        return Command::SUCCESS;
    }

    /**
     * Subscribe every connected Instagram-Login account's webhook fields on
     * graph.instagram.com (account-level subscription — the IG-Login flow has
     * no Facebook Page to subscribe).
     */
    private function subscribeAccounts(): void
    {
        $accounts = InstagramAccount::where('status', 'active')->get();

        if ($accounts->isEmpty()) {
            $this->line('');
            $this->line('No active Instagram module accounts — nothing to subscribe at account level.');

            return;
        }

        $this->line('');
        $this->line('Subscribing Instagram accounts on graph.instagram.com...');

        $client = app(InstagramGraphClient::class);

        foreach ($accounts as $account) {
            $label = sprintf('#%d ws#%d "%s"', $account->id, $account->workspace_id, $account->username ?? $account->ig_user_id);

            try {
                $client->subscribeAccountFields($account);
                $this->line("  [ OK ] $label (ig {$account->ig_user_id})");
            } catch (\Throwable $e) {
                $this->line("  [FAIL] $label: ".$e->getMessage());
                $this->line('         Token expired? Reconnect via "Connect with Instagram".');
            }
        }
    }
}

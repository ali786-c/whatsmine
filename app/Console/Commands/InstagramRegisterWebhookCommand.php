<?php

namespace App\Console\Commands;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Services\MetaWebhookRegistrar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * (Re-)register the app-level `instagram` webhook subscription against Meta
 * AND re-subscribe every connected Facebook Page to Instagram messaging.
 *
 * Use when instagram:diagnose reports "NO instagram webhook subscription at
 * Meta" or a page missing the `messages` field — typically after the
 * verify_token was added/changed, or the subscription was lost. Idempotent:
 * always writes the full expected callback + field set through the shared
 * registrar.
 */
class InstagramRegisterWebhookCommand extends Command
{
    protected $signature = 'instagram:register-webhook';

    protected $description = '(Re-)register the instagram webhook subscription with Meta (app-level callback + fields + page messaging)';

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

        $this->reSubscribePages();

        $this->line('');
        $this->line('Next: send a DM from another Instagram account, then re-run php artisan instagram:diagnose.');

        return Command::SUCCESS;
    }

    /**
     * Re-subscribe every connected Facebook Page to Instagram messaging
     * webhooks (page-level subscribed_apps). Without the `messages` field on
     * the PAGE, Meta never delivers that account's DMs even when the app-level
     * subscription is perfect.
     */
    private function reSubscribePages(): void
    {
        $accounts = ChannelAccount::where('channel', 'instagram')->where('status', 'active')->get();

        if ($accounts->isEmpty()) {
            $this->line('');
            $this->line('No active instagram channel accounts — nothing to re-subscribe at page level.');

            return;
        }

        $this->line('');
        $this->line('Re-subscribing pages to Instagram messaging...');

        foreach ($accounts as $account) {
            $pageId = (string) ($account->meta_json['facebook_page_id'] ?? '');
            $token = (string) ($account->credentials['access_token'] ?? '');
            $label = sprintf('#%d ws#%d "%s"', $account->id, $account->workspace_id, $account->display_name);

            if ($pageId === '' || $token === '') {
                $this->line("  [SKIP] $label — missing facebook_page_id or access_token.");

                continue;
            }

            try {
                $res = Http::withToken($token)
                    ->timeout(20)
                    ->post("https://graph.facebook.com/v20.0/{$pageId}/subscribed_apps", [
                        'subscribed_fields' => 'messages,messaging_postbacks,message_reactions,message_reads',
                    ]);

                if (! $res->successful()) {
                    $this->line("  [FAIL] $label page $pageId: ".($res->json('error.message') ?? $res->body()));
                    $this->line('         Token expired? Reconnect the Instagram account from Channels → Connect Instagram.');

                    continue;
                }

                $check = Http::withToken($token)
                    ->timeout(20)
                    ->get("https://graph.facebook.com/v20.0/{$pageId}/subscribed_apps");
                $fields = (array) $check->json('data.0.subscribed_fields', []);

                if (in_array('messages', $fields, true)) {
                    $this->line("  [ OK ] $label page $pageId subscribed to: ".implode(', ', $fields));
                } else {
                    $this->line("  [FAIL] $label page $pageId — `messages` still missing after subscribe (fields: ".implode(', ', $fields).')');
                }
            } catch (\Throwable $e) {
                $this->line("  [FAIL] $label: ".$e->getMessage());
            }

            Log::info('instagram:register-webhook re-subscribed page', [
                'channel_account_id' => $account->id,
                'page_id' => $pageId,
            ]);
        }
    }
}

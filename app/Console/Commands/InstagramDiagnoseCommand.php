<?php

namespace App\Console\Commands;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Services\MetaWebhookRegistrar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Deep end-to-end diagnosis of the inbound Instagram pipeline.
 *
 * Answers the exact question "I connected Instagram, someone messaged me,
 * but nothing shows in the Inbox — WHERE is it being lost?" by walking every
 * hop a DM takes and reporting the first broken one:
 *
 *   Meta sends webhook → app-level subscription (callback + fields)
 *   → page subscribed to messages → channel_account row matches entry.id
 *   → `instagram` queue has a worker → job forwards DM into Inbox driver
 *   → contact/conversation/message rows + funnel state
 */
class InstagramDiagnoseCommand extends Command
{
    private ?string $lastGraphError = null;

    protected $signature = 'instagram:diagnose
                            {--workspace= : Workspace id to check (default: all workspaces)}';

    protected $description = 'Deep end-to-end check of the Instagram DM/comment pipeline (webhook → queue → Inbox)';

    public function handle(): int
    {
        $ok = '[ OK ]';
        $warn = '[ !! ]';
        $bad = '[FAIL]';
        $hasFailure = false;

        $this->info('=== Instagram pipeline diagnosis — '.now()->toDateTimeString().' ===');

        // ------------------------------------------------------------------
        // 1. Module + config
        // ------------------------------------------------------------------
        $moduleInstalled = class_exists(\App\Modules\Instagram\InstagramServiceProvider::class);

        if (! $moduleInstalled) {
            $this->line("$warn Instagram comment-automation module is NOT installed.");
            $this->line('       In this build the app-level webhook registration is not possible,');
            $this->line('       so Meta has no instagram callback URL and no DMs can ever arrive.');
            $hasFailure = true;
        }

        $moduleEnabled = (bool) config('instagram.enabled', true);
        $queue = (string) config('instagram.queue', 'instagram');

        if ($moduleInstalled && ! $moduleEnabled) {
            $this->line("$bad Module master switch is OFF (INSTAGRAM_ENABLED=false) — webhook routes are not registered at all.");
            $hasFailure = true;
        } else {
            $this->line("$ok Module installed and enabled (queue: \"$queue\").");
        }

        // ------------------------------------------------------------------
        // 2. Meta credentials — the webhook can never fire without these
        // ------------------------------------------------------------------
        $meta = \App\Modules\Integrations\Services\CredentialResolver::system()->meta();
        $appId = $meta?->appId();
        $appSecret = $meta?->appSecret();
        $verifyToken = $meta?->verifyToken();

        $metaMissing = [];
        if (! $appId) {
            $metaMissing[] = 'app_id';
        }
        if (! $appSecret) {
            $metaMissing[] = 'app_secret';
        }
        if (! $verifyToken) {
            $metaMissing[] = 'verify_token';
        }

        if ($metaMissing !== []) {
            $this->line("$bad Meta credentials missing: ".implode(', ', $metaMissing).' (Admin → Integrations → Meta App).');
            $hasFailure = true;
        } else {
            $this->line("$ok Meta credentials present (app_id=$appId).");
        }

        // ------------------------------------------------------------------
        // 3. App-level instagram webhook subscription — verified LIVE against Meta
        // ------------------------------------------------------------------
        if ($appId && $appSecret) {
            $subscription = MetaWebhookRegistrar::verifyInstagramObject();

            if ($subscription === null) {
                $this->line("$bad App has NO `instagram` webhook subscription at Meta (GET /{$appId}/subscriptions returned none).");
                $this->line('       Meta has nowhere to send events → zero DMs/comments can ever arrive.');
                $this->line('       Fix: reconnect the Instagram account once (any connect flow re-registers it),');
                $this->line('       then re-run this command.');
                $hasFailure = true;
            } else {
                $moduleRouteExists = \Illuminate\Support\Facades\Route::has('webhooks.instagram.receive');
                $expectedCallback = rtrim(url($moduleRouteExists ? '/webhooks/instagram' : '/webhooks/meta'), '/').'/';
                $storedCallback = (string) ($subscription['callback_url'] ?? '');
                $storedFields = MetaWebhookRegistrar::normalizeFields($subscription['fields'] ?? []);

                if (! str_starts_with($storedCallback, $expectedCallback)) {
                    $this->line("$bad Callback DRIFT: Meta points at \"$storedCallback\" but this app serves \"$expectedCallback{token}\".");
                    $this->line('       (Setting /webhooks/meta manually in the Meta dashboard overwrites the shared registration');
                    $this->line('        and kills comment automation — the app must own this callback.)');
                    $hasFailure = true;
                } else {
                    $this->line("$ok Callback registered at Meta: $storedCallback");
                }

                $required = $moduleRouteExists
                    ? explode(',', MetaWebhookRegistrar::INSTAGRAM_FIELDS)
                    : explode(',', MetaWebhookRegistrar::INBOX_ONLY_FIELDS);

                foreach ($required as $field) {
                    if (in_array($field, $storedFields, true)) {
                        $this->line("$ok Field subscribed: $field");
                    } else {
                        $this->line($field === 'messages' ? "$bad Field MISSING: $field (no inbound DMs will ever arrive)" : "$bad Field MISSING: $field");
                        $hasFailure = true;
                    }
                }
            }
        }

        // ------------------------------------------------------------------
        // 4. Connected accounts + channel_account mirror + page subscription
        // ------------------------------------------------------------------
        $workspaceId = $this->option('workspace') !== null ? (int) $this->option('workspace') : null;

        $channelAccounts = ChannelAccount::query()
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->where('channel', 'instagram')
            ->get();

        if ($channelAccounts->isEmpty()) {
            $this->line("$bad No instagram channel_accounts rows exist — every forwarded DM is dropped with \"no channel account matched\".");
            $hasFailure = true;
        }

        $tokenByPageId = [];

        foreach ($channelAccounts as $account) {
            $igId = (string) ($account->meta_json['instagram_page_id'] ?? $account->meta_json['instagram_account_id'] ?? '');
            $label = sprintf('channel_account #%d ws#%d "%s" ig_id=%s status=%s', $account->id, $account->workspace_id, $account->display_name, $igId ?: '?', $account->status);

            if ($igId === '' || $account->status !== 'active') {
                $this->line("$bad $label — not active or missing ig id; inbound DMs for it are dropped.");
                $hasFailure = true;
            } else {
                $this->line("$ok $label");
            }

            // Live page-subscription check: without the `messages` field on the
            // PAGE, Meta never delivers that account's DMs to the app.
            $pageToken = (string) ($account->credentials['access_token'] ?? '');
            if ($pageToken !== '' && $appId !== null) {
                $tokenByPageId[$igId] = $pageToken;

                // Token-level diagnosis FIRST — it explains every page-level
                // failure below (missing scopes / expiry).
                if ($appSecret !== null) {
                    $hasFailure = $this->checkStoredPageToken($pageToken, $appId, $appSecret) || $hasFailure;
                }

                $pageId = (string) ($account->meta_json['facebook_page_id'] ?? '');

                if ($pageId !== '') {
                    $subscribed = $this->pageSubscribedFields($pageId, $pageToken);

                    if ($subscribed === null) {
                        $reason = $this->lastGraphError !== null
                            ? ' — Graph says: '.$this->lastGraphError
                            : ' (token may be expired) — check connect logs';
                        $this->line("$warn Could not read page $pageId subscribed_apps$reason.");
                    } elseif (! in_array('messages', $subscribed, true)) {
                        $this->line("$bad Facebook page $pageId is NOT subscribed to `messages` — Meta never delivers this account's DMs.");
                        $this->line('       Fix: reconnect the account so subscribePageToInstagram() runs again.');
                        $hasFailure = true;
                    } else {
                        $this->line("$ok Facebook page $pageId subscribed to `messages`.");
                    }
                } else {
                    $this->line("$warn channel_account #$account->id has no facebook_page_id in meta_json — cannot verify page subscription.");
                }
            }

            // Mirror state in the automation module (comment funnel).
            if ($moduleInstalled) {
                $igAccount = \App\Modules\Instagram\Models\InstagramAccount::where('workspace_id', $account->workspace_id)
                    ->where('ig_user_id', $igId)
                    ->first();

                if ($igAccount && $igAccount->status !== 'active') {
                    $this->line("$warn Automation account for \"$igAccount->username\" (ig_id=$igId) is not active — comment funnels will not fire.");
                }
            }
        }

        // ------------------------------------------------------------------
        // 5. Queue health — the module's jobs ride a dedicated queue
        // ------------------------------------------------------------------
        $this->line('');
        $this->line("--- Queue: $queue ---");

        $defaultQueue = (string) config('queue.default');
        $driver = (string) config("queue.connections.$defaultQueue.driver");

        if ($defaultQueue !== 'sync') {
            $pending = $failed = 0;
            $oldest = null;

            try {
                if ($driver === 'database') {
                    $pending = (int) DB::table('jobs')->where('queue', $queue)->count();
                    $oldest = DB::table('jobs')->where('queue', $queue)->min('available_at');
                    $failed = (int) DB::table('failed_jobs')->where('queue', $queue)->count();
                } elseif ($driver === 'redis') {
                    $pending = (int) Redis::connection()->llen('queues:'.$queue);
                    $failed = (int) DB::table('failed_jobs')->count(); // redis driver: queue not per-row
                }
            } catch (\Throwable $e) {
                $this->line("$warn Could not inspect queue backend: ".$e->getMessage());
            }

            if ($driver !== 'database' && $driver !== 'redis') {
                $this->line("$warn Queue driver is \"$driver\" — inspect it manually for a backlog on \"$queue\".");
            } else {
                $this->line("       pending jobs on \"$queue\": $pending".($failed > 0 ? " / failed: $failed" : ''));

                if ($failed > 0) {
                    $this->line("$warn $failed failed job(s) recorded — inspect storage/logs and php artisan queue:failed.");
                }
                if ($pending > 25) {
                    $this->line("$bad $pending jobs are waiting on \"$queue\" — a worker is very likely NOT running for this queue.");
                    $this->line("       This exact misconfiguration makes DMs appear only after a manual `queue:work` run.");
                    $hasFailure = true;
                } elseif ($pending > 0) {
                    $this->line("$warn $pending job(s) pending — usually fine, but if this number never drains, the worker is missing.");
                } else {
                    $this->line("$ok No backlog on \"$queue\".");
                }
            }
        } else {
            $this->line("$warn QUEUE_CONNECTION=sync — jobs run inline in the web request. Webhook HTTP timing can silently drop them;");
            $this->line('       use database/redis in production with a worker that serves the `instagram` queue.');
        }

        // ------------------------------------------------------------------
        // 6. Funnel state — participants can swallow DMs away from the Inbox
        // ------------------------------------------------------------------
        if ($moduleInstalled) {
            $this->line('');
            $this->line('--- Funnel participants awaiting the follow gate ---');

            $awaiting = \App\Modules\Instagram\Models\FunnelParticipant::query()
                ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
                ->where('stage', \App\Modules\Instagram\Models\FunnelParticipant::STAGE_AWAITING_FOLLOW)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();

            if ($awaiting->isEmpty()) {
                $this->line("$ok None — every inbound DM is forwarded to the Inbox pipeline.");
            } else {
                foreach ($awaiting as $participant) {
                    $this->line("$warn participant #{$participant->id} igsid={$participant->commenter_igsid} user={$participant->username} comment={$participant->comment_id} — their DMs are consumed by the funnel, not mirrored to the Inbox.");
                }
            }
        }

        // ------------------------------------------------------------------
        // 6.5 Comment-automation readiness — the DM flow above does NOT prove
        //     these: automation accounts, active rules and the scheduler cron.
        // ------------------------------------------------------------------
        if ($moduleInstalled) {
            $this->line('');
            $this->line('--- Comment automation readiness ---');

            $accountsTotal = \App\Modules\Instagram\Models\InstagramAccount::count();
            $accountsActive = \App\Modules\Instagram\Models\InstagramAccount::where('status', 'active')->count();

            if ($accountsActive === 0) {
                $this->line("$bad No active comment-automation accounts — connect via Instagram → Setup (the Inbox connection alone does not create one).");
                $hasFailure = true;
            } else {
                $this->line("$ok Comment-automation accounts: $accountsActive active / $accountsTotal total.");
            }

            $automations = \App\Modules\Instagram\Models\CommentAutomation::where('is_active', true)->count();

            if ($accountsActive > 0 && $automations === 0) {
                $this->line("$warn No active comment automations — comments will be received but NEVER replied to. Create one: Instagram → Automations → New.");
            } elseif ($automations > 0) {
                $this->line("$ok Active comment automations: $automations.");
            }

            // The timeout sweep (7-day/24h windows, stuck private-reply retries)
            // runs through the scheduler. Without the schedule:run cron the funnel
            // slowly rots: stranded sends never retry and windows never expire.
            try {
                $heartbeat = \Illuminate\Support\Facades\Cache::get(\App\Http\Controllers\Admin\CronSetupController::HEARTBEAT_KEY);
            } catch (\Throwable) {
                $heartbeat = null;
            }

            if ($heartbeat === null) {
                $this->line("$bad Scheduler has NEVER run — the `* * * * * php artisan schedule:run` cron is missing on this server.");
                $this->line('       Without it: 7-day/24h windows never expire, stuck private replies never retry, and scheduled');
                $this->line('       broadcasts/social posts also silently die. Add the cron (Admin → Cron Setup guide).');
                $hasFailure = true;
            } else {
                $ageMin = (int) now()->diffInMinutes(\Illuminate\Support\Carbon::parse($heartbeat));

                if ($ageMin > 10) {
                    $this->line("$warn Scheduler last ran $ageMin minute(s) ago — the schedule:run cron is missing or intermittent.");
                    $this->line('       Funnel windows/stranded-send healing depend on it — add the cron (Admin → Cron Setup).');
                } else {
                    $this->line("$ok Scheduler running (last heartbeat: $ageMin min ago).");
                }
            }
        }

        // ------------------------------------------------------------------
        // 7. Job class self-test (dependency-free — PHPUnit is absent on prod)
        // ------------------------------------------------------------------
        $this->line('');
        $this->line('--- Job self-test ---');

        try {
            $jobClass = \App\Modules\Instagram\Jobs\ProcessInstagramDmJob::class;
            $ref = new \ReflectionClass($jobClass);

            if (! $ref->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class)) {
                throw new \RuntimeException($jobClass.' does not implement ShouldQueue');
            }

            $this->line("$ok $jobClass is loadable and queueable — it rides the \"$queue\" queue.");
            $this->line('       Confirm a worker serves it:  ps aux | grep queue:work');
        } catch (\Throwable $e) {
            $this->line("$bad Job self-test failed: ".$e->getMessage());
            $hasFailure = true;
        }

        // ------------------------------------------------------------------
        // 8. Where to look next — actionable pointers
        // ------------------------------------------------------------------
        $this->line('');
        $this->line('--- If everything above is OK but messages are still missing ---');
        $this->line('1. Send a DM from another Instagram account, then immediately check:');
        $this->line('   storage/logs/laravel.log  → grep "Instagram webhook"');
        $this->line('   storage/logs/instagram/   → webhook.log, dm.log, mirror.log');
        if ($verifyToken) {
            $this->line('2. Verify Meta can reach the webhook from the outside:');
            $this->line('   curl "https://'.parse_url((string) config('app.url'), PHP_URL_HOST).'/webhooks/instagram/'.$verifyToken.'?hub.mode=subscribe&hub.verify_token='.$verifyToken.'&hub.challenge=hello"');
            $this->line('   (must echo "hello" — anything else means token mismatch or a proxy problem)');
        }
        $this->line('3. If laravel.log shows nothing at all, the POST never reached this server:');
        $this->line('   check the Meta App Dashboard → Webhooks → instagram → recent deliveries.');
        $this->line('4. Confirm the sender is NOT the account owner — Meta does not webhook');
        $this->line('   messages an account sends to itself.');

        $this->line('');

        return $hasFailure ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Read the page's subscribed fields live from Graph. Returns null when the
     * read fails (bad token, network) — distinguishable from an empty list.
     *
     * @return array<int, string>|null
     */
    private function pageSubscribedFields(string $pageId, string $pageToken): ?array
    {
        $this->lastGraphError = null;

        try {
            $res = \Illuminate\Support\Facades\Http::withToken($pageToken)
                ->timeout(15)
                ->get("https://graph.facebook.com/v20.0/{$pageId}/subscribed_apps");

            if (! $res->successful()) {
                $this->lastGraphError = (string) ($res->json('error.message') ?? ('HTTP '.$res->status()));

                return null;
            }

            $fields = $res->json('data.0.subscribed_fields', []);

            return is_array($fields) ? array_map('strval', $fields) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Inspect the stored page token LIVE via /debug_token: validity, expiry
     * and — critically — whether `pages_messaging` is granted. Meta requires
     * that permission before a page can subscribe to `messages`, and its
     * absence is the #1 cause of "connected, but no DMs ever arrive"
     * (Graph error #200 on /subscribed_apps).
     */
    private function checkStoredPageToken(string $pageToken, string $appId, string $appSecret): bool
    {
        $ok = '[ OK ]';
        $warn = '[ !! ]';
        $bad = '[FAIL]';

        try {
            $res = \Illuminate\Support\Facades\Http::timeout(15)
                ->get('https://graph.facebook.com/v20.0/debug_token', [
                    'input_token' => $pageToken,
                    'access_token' => $appId.'|'.$appSecret,
                ]);
        } catch (\Throwable) {
            $this->line("$warn Could not inspect the stored page token (/debug_token unreachable).");

            return false;
        }

        if (! $res->successful()) {
            $this->line("$warn Could not inspect the stored page token (/debug_token: HTTP ".$res->status().').');

            return false;
        }

        $data = $res->json('data');

        if (! is_array($data)) {
            $this->line("$warn /debug_token returned an unexpected shape — cannot inspect token scopes.");

            return false;
        }

        $failed = false;
        $scopes = array_values(array_map('strval', (array) ($data['scopes'] ?? [])));
        $expiresAt = (int) ($data['expires_at'] ?? 0);
        $expiryText = $expiresAt === 0 ? 'never expires' : 'expires '.date('Y-m-d H:i', $expiresAt);

        if (($data['is_valid'] ?? false) !== true) {
            $this->line("$bad Stored page token is INVALID at Meta (is_valid=false) — every page-level call fails with it.");
            $this->line('       Fix: reconnect the account — Channels → Connect Instagram.');
            $failed = true;
        } elseif ($expiresAt !== 0 && $expiresAt < time()) {
            $this->line("$bad Stored page token EXPIRED ($expiryText) — every page-level call fails with it.");
            $this->line('       Fix: reconnect the account — Channels → Connect Instagram.');
            $failed = true;
        } else {
            $this->line("$ok Stored page token is valid ($expiryText).");
        }

        if (in_array('pages_messaging', $scopes, true)) {
            $this->line("$ok Token scope: pages_messaging granted.");
        } else {
            $this->line("$bad Token scope MISSING: pages_messaging — Meta refuses to subscribe the page to `messages` (error #200).");
            $this->line('       Fix: 1) developers.facebook.com → your app → Facebook Login for Business → Configurations →');
            $this->line('             Social config → add `pages_messaging` → Save.');
            $this->line('          2) Reconnect the account: Channels → Connect Instagram (the new token picks it up automatically).');
            $failed = true;
        }

        foreach (['instagram_manage_messages', 'pages_manage_metadata'] as $needed) {
            if (! in_array($needed, $scopes, true)) {
                $this->line("$warn Token scope also missing: $needed — sending DMs / page subscribe will fail even after `pages_messaging` is added.");
            }
        }

        return $failed;
    }
}

<?php

namespace App\Modules\Instagram\Jobs;

use App\Modules\Instagram\Models\InstagramAccount;
use App\Modules\Instagram\Services\InstagramAuthService;
use App\Modules\Instagram\Services\InstagramLog;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Instagram-Login tokens are 60-day Instagram User tokens (unlike the
 * never-expiring Facebook Page tokens of the legacy flow). This job refreshes
 * each long-lived token before it expires — scheduled daily.
 *
 * Refresh rules (Meta): a long-lived token can be refreshed once it is at
 * least 24h old and is not already expired; each refresh returns a NEW 60-day
 * token, so running daily keeps accounts connected indefinitely.
 *
 * Both stored copies are updated: instagram_accounts.page_token (comment
 * automation) and the Inbox channel_accounts credentials (DM pipeline).
 */
class RefreshInstagramLoginTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** Refresh when the token expires within this many days. */
    public const EXPIRY_WINDOW_DAYS = 14;

    public function handle(): void
    {
        $accounts = InstagramAccount::query()
            ->where('status', 'active')
            ->whereJsonContains('meta_json->auth_type', 'instagram_login')
            ->get();

        $refreshed = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            $metaJson = (array) $account->meta_json;

            // Skip tokens refreshed recently — Meta only allows a refresh on
            // tokens older than 24h anyway.
            $refreshedAt = isset($metaJson['token_refreshed_at']) ? \Illuminate\Support\Carbon::parse($metaJson['token_refreshed_at']) : null;
            if ($refreshedAt && $refreshedAt->gt(now()->subHours(23))) {
                continue;
            }

            $expiresAt = isset($metaJson['token_expires_at']) ? \Illuminate\Support\Carbon::parse($metaJson['token_expires_at']) : null;
            if ($expiresAt && $expiresAt->gt(now()->addDays(self::EXPIRY_WINDOW_DAYS))) {
                continue; // plenty of runway — nothing to do yet
            }

            $newToken = app(InstagramAuthService::class)->refreshLongLived((string) $account->page_token);

            if (! $newToken) {
                $failed++;
                InstagramLog::connect('warning', 'ig_login: token refresh failed — account may disconnect on expiry', [
                    'ig_user_id' => $account->ig_user_id,
                    'token_expires_at' => $metaJson['token_expires_at'] ?? null,
                ]);

                continue;
            }

            $newMeta = array_merge($metaJson, [
                'token_refreshed_at' => now()->toIso8601String(),
                'token_expires_at' => now()->addSeconds($newToken['expires_in'])->toIso8601String(),
            ]);

            $account->update(['page_token' => $newToken['access_token'], 'meta_json' => $newMeta]);

            // Keep the Inbox mirror in sync so the DM pipeline keeps sending.
            try {
                ChannelAccount::where('channel', 'instagram')
                    ->whereJsonContains('meta_json->instagram_page_id', (string) $account->ig_user_id)
                    ->get()
                    ->each(function (ChannelAccount $channelAccount) use ($newToken, $newMeta): void {
                        $channelAccount->update([
                            'credentials' => array_merge($channelAccount->credentials ?? [], ['access_token' => $newToken['access_token']]),
                            'meta_json' => array_merge($channelAccount->meta_json ?? [], [
                                'token_refreshed_at' => $newMeta['token_refreshed_at'],
                                'token_expires_at' => $newMeta['token_expires_at'],
                            ]),
                        ]);
                    });
            } catch (\Throwable $e) {
                // Inbox module may be stripped on shared builds — never fatal.
            }

            $refreshed++;
            InstagramLog::connect('info', 'ig_login: token refreshed', [
                'ig_user_id' => $account->ig_user_id,
                'expires_in_days' => (int) ceil($newToken['expires_in'] / 86400),
            ]);
        }

        if ($refreshed > 0 || $failed > 0) {
            Log::info('ig_login: token refresh sweep complete', ['refreshed' => $refreshed, 'failed' => $failed, 'considered' => $accounts->count()]);
        }
    }
}

<?php

namespace App\Modules\Instagram\Services;

use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Instagram\Models\InstagramAccount;
use Illuminate\Support\Facades\Http;

/**
 * Business Login for Instagram (Instagram API with Instagram Login).
 *
 * Hosts used by this flow:
 *  - www.instagram.com/oauth/authorize   → authorization window
 *  - api.instagram.com/oauth/access_token → short-lived token exchange (POST)
 *  - graph.instagram.com/access_token     → long-lived (ig_exchange_token) + refresh (ig_refresh_token)
 *  - graph.instagram.com/{v}/me           → account identity
 *
 * IMPORTANT ID MAPPING: the /me response comes back as a `data: [...]` ARRAY and
 * its `user_id` value IS the Instagram professional account ID that appears as
 * webhook `entry.id` — it is stored as ig_user_id. There is NO Facebook Page in
 * this flow: accounts connect with Instagram credentials only.
 */
class InstagramAuthService
{
    /** Default scopes for the connect flow (comment automation + DMs). */
    public const SCOPES = 'instagram_business_basic,instagram_business_manage_messages,instagram_business_manage_comments';

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        $igAppId = CredentialResolver::system()->meta()?->igAppId();

        $params = http_build_query([
            'client_id' => $igAppId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'state' => $state,
        ]);

        return 'https://www.instagram.com/oauth/authorize?'.$params;
    }

    /**
     * Exchange the OAuth ?code= for a short-lived Instagram User access token.
     *
     * @return array{access_token: string, user_id: string}|null
     */
    public function exchangeCode(string $code, string $redirectUri): ?array
    {
        $meta = CredentialResolver::system()->meta();
        $igAppId = $meta?->igAppId();
        $igAppSecret = $meta?->igAppSecret();

        if (! $igAppId || ! $igAppSecret) {
            InstagramLog::connect('warning', 'ig_login: code exchange skipped — Instagram App ID/Secret not configured', []);

            return null;
        }

        $res = Http::asForm()->post('https://api.instagram.com/oauth/access_token', [
            'client_id' => $igAppId,
            'client_secret' => $igAppSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if (! $res->successful() || ! $res->json('access_token')) {
            InstagramLog::connect('warning', 'ig_login: code exchange failed', [
                'status' => $res->status(),
                'response' => $res->json() ?: $res->body(),
            ]);

            return null;
        }

        return [
            'access_token' => (string) $res->json('access_token'),
            'user_id' => (string) $res->json('user_id', ''),
        ];
    }

    /**
     * Exchange a short-lived token for a 60-day long-lived token.
     */
    public function exchangeForLongLived(string $shortToken): ?array
    {
        $igAppSecret = CredentialResolver::system()->meta()?->igAppSecret();

        if (! $igAppSecret) {
            InstagramLog::connect('warning', 'ig_login: long-lived exchange skipped — secret missing', []);

            return null;
        }

        $res = Http::get('https://graph.instagram.com/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $igAppSecret,
            'access_token' => $shortToken,
        ]);

        if (! $res->successful() || ! $res->json('access_token')) {
            InstagramLog::connect('warning', 'ig_login: long-lived exchange failed', [
                'status' => $res->status(),
                'response' => $res->json() ?: $res->body(),
            ]);

            return null;
        }

        return [
            'access_token' => (string) $res->json('access_token'),
            'expires_in' => (int) $res->json('expires_in', 5184000),
        ];
    }

    /**
     * Refresh a long-lived token (valid once it is at least 24h old and not expired).
     *
     * @return array{access_token: string, expires_in: int}|null
     */
    public function refreshLongLived(string $longToken): ?array
    {
        $res = Http::get('https://graph.instagram.com/refresh_access_token', [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $longToken,
        ]);

        if (! $res->successful() || ! $res->json('access_token')) {
            InstagramLog::connect('warning', 'ig_login: token refresh failed', [
                'status' => $res->status(),
                'response' => $res->json() ?: $res->body(),
            ]);

            return null;
        }

        return [
            'access_token' => (string) $res->json('access_token'),
            'expires_in' => (int) $res->json('expires_in', 5184000),
        ];
    }

    /**
     * GET graph.instagram.com/{v}/me — the response arrives as a `data: [...]`
     * ARRAY; tolerate the object shape too. `user_id` is the IG professional
     * account ID that matches webhook entry.id.
     *
     * @return array{user_id: string, username: ?string, name: ?string, account_type: ?string, profile_picture_url: ?string}|null
     */
    public function fetchAccount(string $accessToken): ?array
    {
        $res = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(20)
            ->get($this->graphBaseUrl().'/me', [
                'fields' => 'user_id,username,name,account_type,profile_picture_url',
            ]);

        if (! $res->successful()) {
            InstagramLog::connect('warning', 'ig_login: /me fetch failed', [
                'status' => $res->status(),
                'response' => $res->json() ?: $res->body(),
            ]);

            return null;
        }

        // /me returns data: [ {...} ] — take the first element; tolerate object shape.
        $payload = $res->json();
        $account = $payload['data'][0] ?? (is_array($payload) && isset($payload['user_id']) ? $payload : null);

        if (! is_array($account) || blank($account['user_id'] ?? null)) {
            InstagramLog::connect('warning', 'ig_login: /me response missing user_id', ['payload_keys' => array_keys((array) $payload)]);

            return null;
        }

        return [
            'user_id' => (string) $account['user_id'],
            'username' => isset($account['username']) ? (string) $account['username'] : null,
            'name' => isset($account['name']) ? (string) $account['name'] : null,
            'account_type' => isset($account['account_type']) ? (string) $account['account_type'] : null,
            'profile_picture_url' => isset($account['profile_picture_url']) ? (string) $account['profile_picture_url'] : null,
        ];
    }

    private function graphBaseUrl(): string
    {
        return 'https://graph.instagram.com/'.(string) config('instagram.api_version', 'v20.0');
    }
}

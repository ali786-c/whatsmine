<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Instagram\Models\InstagramAccount;
use App\Modules\Instagram\Services\InstagramGraphClient;
use App\Modules\Instagram\Services\InstagramLog;
use App\Modules\Integrations\Services\CredentialResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ConnectController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);
        $meta = CredentialResolver::system()->meta();

        // Business Login for Instagram: pre-build the authorize URL so the UI
        // only needs to link to it. Instagram App ID/Secret must be configured
        // by the admin for this to be non-null.
        $igAuthUrl = null;
        if ($meta?->igAppId() && $meta?->igAppSecret()) {
            $state = bin2hex(random_bytes(8));
            session(['ig_login_state' => $state]);
            $igAuthUrl = app(\App\Modules\Instagram\Services\InstagramAuthService::class)
                ->authorizeUrl(route('client.instagram.setup'), $state);
        }

        return Inertia::render('Instagram/Setup', [
            'accounts' => InstagramAccount::where('workspace_id', $workspaceId)
                ->get(['id', 'ig_user_id', 'username', 'display_name', 'page_id', 'status', 'meta_json', 'created_at']),
            'automationsCount' => \App\Modules\Instagram\Models\CommentAutomation::where('workspace_id', $workspaceId)->count(),
            'metaAppId' => $meta?->appId() ?: null,
            'metaConfigIdSocial' => $meta?->configIdSocial() ?: null,
            'webhookUrl' => $meta?->verifyToken() ? route('webhooks.instagram.receive', ['token' => $meta->verifyToken()]) : null,
            'igAuthUrl' => $igAuthUrl,
        ]);
    }

    /**
     * Business Login for Instagram redirect-back: Instagram credentials flow,
     * graph.instagram.com token, NO Facebook Page. Persists both module and
     * Inbox rows with auth_type='instagram_login' and auto-registers webhooks.
     */
    public function connectInstagramLogin(Request $request): RedirectResponse
    {
        $workspaceId = $this->workspaceId($request);
        $code = (string) $request->input('code', '');
        $error = (string) $request->input('error_description', '');
        $setupRoute = route('client.instagram.setup');

        if ($error !== '' || $code === '') {
            return redirect()->to($setupRoute)
                ->with('flash.error', $error !== '' ? $error : 'Instagram authorization was cancelled or returned no code.');
        }

        // CSRF-style state check (set when the authorize URL was built).
        $expectedState = (string) session('ig_login_state', '');
        if ($expectedState !== '' && ! hash_equals($expectedState, (string) $request->input('state', ''))) {
            return redirect()->to($setupRoute)->with('flash.error', 'Instagram login state mismatch — please start the connection again.');
        }
        session()->forget('ig_login_state');

        $meta = CredentialResolver::system()->meta();
        if (! $meta?->igAppId() || ! $meta?->igAppSecret()) {
            return redirect()->to($setupRoute)
                ->with('flash.error', 'Instagram App credentials are not configured. Ask your administrator to fill Instagram App ID/Secret in Admin → Integrations → Meta App.');
        }

        $auth = app(\App\Modules\Instagram\Services\InstagramAuthService::class);

        $short = $auth->exchangeCode($code, $setupRoute);
        if (! $short) {
            return redirect()->to($setupRoute)->with('flash.error', 'Failed to exchange the authorization code with Instagram.');
        }

        $long = $auth->exchangeForLongLived($short['access_token']);
        $token = $long['access_token'] ?? $short['access_token'];
        $expiresIn = $long['expires_in'] ?? 3600;

        $account = $auth->fetchAccount($token);
        if (! $account) {
            return redirect()->to($setupRoute)->with('flash.error', 'Connected, but could not read your Instagram account details. Please retry.');
        }

        $this->persistInstagramLogin($workspaceId, $account, $token, $expiresIn);

        return redirect()->to($setupRoute)
            ->with('flash.success', 'Connected Instagram account '.($account['username'] ?? $account['user_id']).' via Instagram Login.');
    }

    /**
     * Shared persistence for the IG-login flow (module + Inbox rows),
     * automatic webhook registration and best-effort field subscription.
     * NO page subscription — this flow has no Facebook Page.
     */
    private function persistInstagramLogin(int $workspaceId, array $account, string $token, int $expiresIn): void
    {
        $igId = $account['user_id'];
        $name = $account['username'] ?? ($account['name'] ?? $igId);

        $igAccount = InstagramAccount::updateOrCreate(
            ['workspace_id' => $workspaceId, 'ig_user_id' => $igId],
            [
                'username' => $account['username'] ?? null,
                'display_name' => $account['name'] ?? ($account['username'] ?? $igId),
                'page_id' => null,
                'page_token' => $token,
                'status' => 'active',
                'meta_json' => [
                    'connected_at' => now()->toIso8601String(),
                    'auth_type' => 'instagram_login',
                    'token_expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
                    'token_refreshed_at' => now()->toIso8601String(),
                    'connected_via' => 'instagram_login',
                ],
            ],
        );

        // Inbox mirror so DMs land in the shared Inbox (same shape the Inbox
        // flow persists; auth_type lets the driver pick the IG host).
        if (class_exists(\App\Modules\Shared\Models\ChannelAccount::class)) {
            try {
                $metaJson = [
                    'instagram_page_id' => $igId,
                    'instagram_account_id' => $igId,
                    'auth_type' => 'instagram_login',
                    'token_expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
                    'token_refreshed_at' => now()->toIso8601String(),
                ];

                $existing = \App\Modules\Shared\Models\ChannelAccount::where('workspace_id', $workspaceId)
                    ->where('channel', 'instagram')
                    ->whereJsonContains('meta_json->instagram_page_id', $igId)
                    ->first();

                if ($existing) {
                    $existing->update([
                        'credentials' => array_merge($existing->credentials ?? [], ['access_token' => $token, 'instagram_account_id' => $igId]),
                        'meta_json' => array_merge($existing->meta_json ?? [], $metaJson),
                        'status' => 'active',
                    ]);
                } else {
                    \App\Modules\Shared\Models\ChannelAccount::create([
                        'workspace_id' => $workspaceId,
                        'channel' => 'instagram',
                        'provider' => 'meta',
                        'display_name' => mb_substr((string) $name, 0, 128),
                        'credentials' => ['access_token' => $token, 'instagram_account_id' => $igId],
                        'meta_json' => $metaJson,
                        'status' => 'active',
                    ]);
                }
            } catch (\Throwable $e) {
                InstagramLog::connect('warning', 'ig_login: inbox channel_account sync failed (DMs may not reach Inbox)', [
                    'ig_user_id' => $igId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Automatic webhook registration (shared registrar — idempotent).
        \App\Modules\Shared\Services\MetaWebhookRegistrar::registerInstagramObject();

        // Best-effort account field subscription (graph.instagram.com host).
        try {
            app(InstagramGraphClient::class)->subscribeAccountFields($igAccount);
        } catch (\Throwable $e) {
            InstagramLog::connect('warning', 'ig_login: account field subscription failed (webhooks may still deliver)', [
                'ig_user_id' => $igId,
                'error' => $e->getMessage(),
            ]);
        }

        InstagramLog::connect('info', 'ig_login: account connected via Instagram Login', [
            'workspace_id' => $workspaceId,
            'ig_user_id' => $igId,
            'username' => $account['username'] ?? null,
        ]);
    }

    /**
     * Facebook OAuth redirect-back handler: exchange the ?code= for tokens,
     * fetch managed Pages with IG professional accounts, persist each one and
     * (re)register the app-level `instagram` webhook subscription.
     */
    public function connect(Request $request): RedirectResponse
    {
        $workspaceId = $this->workspaceId($request);
        $code = (string) $request->input('code', '');
        $error = (string) $request->input('error_description', '');

        if ($error !== '' || $code === '') {
            return redirect()->route('client.instagram.setup')
                ->with('flash.error', $error !== '' ? $error : 'Instagram authorization was cancelled or returned no code.');
        }

        $meta = CredentialResolver::system()->meta();
        if (! $meta?->appId() || ! $meta?->appSecret()) {
            return redirect()->route('client.instagram.setup')
                ->with('flash.error', 'Meta App credentials are not configured. Ask your administrator to configure them in Admin → Integrations → Meta App.');
        }

        $redirectUri = route('client.instagram.setup');
        $shortToken = $this->exchangeCodeForToken($code, $redirectUri);
        if (! $shortToken) {
            return redirect()->route('client.instagram.setup')
                ->with('flash.error', 'Failed to exchange the authorization code with Meta.');
        }

        $longToken = $this->exchangeForLongLivedToken($shortToken);

        // Register the app-level `instagram` object through the SHARED registrar —
        // the same one the Inbox connect flow uses. One callback URL, one superset
        // field list, identical outcome no matter which connect flow runs last.
        app(\App\Modules\Shared\Services\MetaWebhookRegistrar::class)->registerInstagramObject();

        $pagesRes = Http::withToken($longToken)
            ->get("https://graph.facebook.com/{$this->apiVersion()}/me/accounts", [
                'fields' => 'id,name,access_token,instagram_business_account{id,name,username}',
                'limit' => 50,
            ]);

        if (! $pagesRes->successful()) {
            InstagramLog::connect('warning', 'instagram_module: pages fetch failed', ['response' => $pagesRes->json()]);

            return redirect()->route('client.instagram.setup')
                ->with('flash.error', 'Could not fetch your Facebook pages: '.($pagesRes->json('error.message') ?? 'unknown error'));
        }

        $connected = 0;
        foreach ((array) $pagesRes->json('data', []) as $page) {
            $ig = $page['instagram_business_account'] ?? null;
            if (empty($ig['id'])) {
                continue;
            }

            $igId = (string) $ig['id'];
            $pageToken = (string) ($page['access_token'] ?? $longToken);

            $account = InstagramAccount::updateOrCreate(
                ['workspace_id' => $workspaceId, 'ig_user_id' => $igId],
                [
                    'username' => $ig['username'] ?? null,
                    'display_name' => $ig['name'] ?? ($page['name'] ?? $igId),
                    'page_id' => (string) ($page['id'] ?? ''),
                    'page_token' => $pageToken,
                    'status' => 'active',
                    'meta_json' => [
                        'connected_at' => now()->toIso8601String(),
                        'facebook_page_name' => $page['name'] ?? null,
                    ],
                ],
            );

            // Best-effort per-account webhook field subscription.
            try {
                app(InstagramGraphClient::class)->subscribeAccountFields($account);
            } catch (\Throwable $e) {
                InstagramLog::connect('warning', 'instagram_module: account field subscription failed', [
                    'ig_user_id' => $igId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Subscribe the linked Facebook Page to Instagram messaging webhooks
            // (same step the Inbox connect flow performs). Without it Meta does
            // not deliver inbound IG messages at all.
            try {
                app(InstagramGraphClient::class)->subscribePageToMessaging($account->page_id, $pageToken);
                InstagramLog::connect('info', 'instagram_module: page subscribed to messaging webhooks', [
                    'page_id' => $account->page_id,
                    'ig_user_id' => $igId,
                ]);
            } catch (\Throwable $e) {
                InstagramLog::connect('warning', 'instagram_module: page messaging subscription failed', [
                    'page_id' => $account->page_id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Create/update the Inbox module's channel_accounts row so DMs flow
            // into the shared Inbox even when connected from THIS panel. The
            // Inbox InstagramDriver matches inbound messages on meta_json
            // instagram_page_id / instagram_account_id — without this row it
            // drops every forwarded DM. Class-guarded: Inbox absent → skipped.
            $this->mirrorChannelAccount($workspaceId, $account, $pageToken, $page);

            $connected++;
        }

        if ($connected === 0) {
            return redirect()->route('client.instagram.setup')
                ->with('flash.error', 'No Instagram professional accounts were found on your Facebook Pages. Convert the account to a professional (business/creator) account and try again.');
        }

        return redirect()->route('client.instagram.setup')
            ->with('flash.success', "Connected {$connected} Instagram account".($connected === 1 ? '' : 's').'.');
    }

    public function disconnect(Request $request, InstagramAccount $instagramAccount): RedirectResponse
    {
        $workspaceId = $this->workspaceId($request);
        abort_unless((int) $instagramAccount->workspace_id === (int) $workspaceId, 403);

        $instagramAccount->update(['status' => 'disconnected']);

        return redirect()->route('client.instagram.setup')->with('flash.success', 'Instagram account disconnected.');
    }

    /** Convenience JSON probe used by the Setup page after OAuth. */
    public function status(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);

        return response()->json([
            'accounts' => InstagramAccount::where('workspace_id', $workspaceId)
                ->get(['id', 'ig_user_id', 'username', 'status']),
        ]);
    }

    private function exchangeCodeForToken(string $code, string $redirectUri): ?string
    {
        $meta = CredentialResolver::system()->meta();
        $params = [
            'client_id' => $meta->appId(),
            'client_secret' => $meta->appSecret(),
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];

        $res = Http::get("https://graph.facebook.com/{$this->apiVersion()}/oauth/access_token", $params);

        // Meta binds codes to the exact redirect URI; fall back like the Inbox flow does.
        if ((! $res->successful() || ! $res->json('access_token'))) {
            unset($params['redirect_uri']);
            $res = Http::get("https://graph.facebook.com/{$this->apiVersion()}/oauth/access_token", $params);
        }

        if (! $res->successful() || ! $res->json('access_token')) {
            InstagramLog::connect('warning', 'instagram_module: code exchange failed', ['response' => $res->json() ?: $res->body()]);

            return null;
        }

        return (string) $res->json('access_token');
    }

    private function exchangeForLongLivedToken(string $shortToken): string
    {
        $meta = CredentialResolver::system()->meta();
        if (! $meta?->appId() || ! $meta?->appSecret()) {
            return $shortToken;
        }

        $res = Http::get("https://graph.facebook.com/{$this->apiVersion()}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $meta->appId(),
            'client_secret' => $meta->appSecret(),
            'fb_exchange_token' => $shortToken,
        ]);

        return ($res->successful() && $res->json('access_token'))
            ? (string) $res->json('access_token')
            : $shortToken;
    }

    private function apiVersion(): string
    {
        return (string) config('instagram.api_version', 'v20.0');
    }

    /**
     * Keep the Inbox module's channel_accounts row in sync with this connection
     * (same persisted shape as the Inbox's own embedded-signup flow). This is
     * what lets the Inbox InstagramDriver match inbound messages forwarded from
     * our webhook — without the row it logs "no channel account matched" and
     * drops the DM. Best-effort: any failure is logged, never fatal.
     */
    private function mirrorChannelAccount(int $workspaceId, InstagramAccount $account, string $pageToken, array $page): void
    {
        if (! class_exists(\App\Modules\Shared\Models\ChannelAccount::class)) {
            return;
        }

        try {
            $igId = $account->ig_user_id;
            $pageId = (string) ($page['id'] ?? $account->page_id);
            $name = $account->username ?: ($account->display_name ?: $igId);

            $credentials = ['access_token' => $pageToken, 'instagram_account_id' => $igId];
            $metaJson = [
                'instagram_page_id' => $igId,
                'instagram_account_id' => $igId,
                'facebook_page_id' => $pageId,
            ];

            $existing = \App\Modules\Shared\Models\ChannelAccount::where('workspace_id', $workspaceId)
                ->where('channel', 'instagram')
                ->whereJsonContains('meta_json->instagram_page_id', $igId)
                ->first();

            if ($existing) {
                // Merge so Inbox-side extras (e.g. assigned ai_chatbot_id) survive.
                $existing->update([
                    'credentials' => $credentials,
                    'meta_json' => array_merge($existing->meta_json ?? [], $metaJson),
                    'status' => 'active',
                ]);
            } else {
                \App\Modules\Shared\Models\ChannelAccount::create([
                    'workspace_id' => $workspaceId,
                    'channel' => 'instagram',
                    'provider' => 'meta',
                    'display_name' => mb_substr((string) $name, 0, 128),
                    'credentials' => $credentials,
                    'meta_json' => $metaJson,
                    'status' => 'active',
                ]);
            }

            InstagramLog::connect('info', 'instagram_module: inbox channel_account synced', [
                'ig_user_id' => $igId,
                'workspace_id' => $workspaceId,
                'updated' => (bool) $existing,
            ]);
        } catch (\Throwable $e) {
            InstagramLog::connect('warning', 'instagram_module: inbox channel_account sync failed (DMs may not reach Inbox)', [
                'ig_user_id' => $account->ig_user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}

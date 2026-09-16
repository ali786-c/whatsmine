<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class InboxSetupController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        // WhatsApp WABAs
        $wabas = WhatsappBusinessAccount::where('workspace_id', $workspaceId)
            ->with('phoneNumbers')
            ->get();

        $whatsappChannelAccounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'whatsapp')
            ->whereNotNull('phone_number_id')
            ->get(['id', 'phone_number_id', 'display_name', 'status', 'meta_json', 'business_account_id']);

        $webhookTokensByWaba = [];
        $channelAccountPhoneIdsByWaba = [];
        $channelAccountsByWaba = [];
        foreach ($wabas as $waba) {
            $webhookTokensByWaba[$waba->id] = $waba->makeVisible('webhook_verify_token')->webhook_verify_token;
            $accounts = $whatsappChannelAccounts->where('business_account_id', $waba->waba_id)->values();
            $channelAccountPhoneIdsByWaba[$waba->id] = $accounts->pluck('phone_number_id')->all();
            $channelAccountsByWaba[$waba->id] = $accounts->map(fn ($a) => [
                'id'              => $a->id,
                'phone_number_id' => $a->phone_number_id,
                'display_name'    => $a->display_name,
                'status'          => $a->status,
                'ai_chatbot_id'   => $a->meta_json['ai_chatbot_id'] ?? null,
            ])->all();
        }

        // Instagram / Messenger
        $instagramAccounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'instagram')
            ->get(['id', 'display_name', 'status', 'meta_json', 'created_at'])
            ->map(fn ($a) => array_merge($a->toArray(), ['ai_chatbot_id' => $a->meta_json['ai_chatbot_id'] ?? null]));

        $messengerAccounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'messenger')
            ->get(['id', 'display_name', 'status', 'meta_json', 'created_at'])
            ->map(fn ($a) => array_merge($a->toArray(), ['ai_chatbot_id' => $a->meta_json['ai_chatbot_id'] ?? null]));

        $chatbots = AiChatbot::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->get(['id', 'name']);

        $meta = CredentialResolver::system()->meta();
        $metaWebhookUrl = $meta ? url('/webhooks/meta/'.$meta->verifyToken()) : null;

        $metaCreds = CredentialResolver::system()->meta();
        $igAuthService = $metaCreds?->igAppId() ? app(\App\Modules\Instagram\Services\InstagramAuthService::class) : null;

        return Inertia::render('Inbox/Setup', [
            'wabas'                        => $wabas,
            'whatsappWebhookUrl'           => url('/webhooks/whatsapp'),
            'whatsappWebhookGlobalUrl'     => route('webhooks.whatsapp.global.receive'),
            'webhookTokensByWaba'          => $webhookTokensByWaba,
            'channelAccountPhoneIdsByWaba' => $channelAccountPhoneIdsByWaba,
            'channelAccountsByWaba'        => $channelAccountsByWaba,
            'instagramAccounts'            => $instagramAccounts,
            'messengerAccounts'            => $messengerAccounts,
            'chatbots'                     => $chatbots,
            'metaWebhookUrl'               => $metaWebhookUrl,
            'metaAppId'                    => $metaCreds?->appId() ?: null,
            'igAppId'                      => $metaCreds?->igAppId() ?: null,
            'igAuthorizeUrl'               => $igAuthService ? $igAuthService->authorizeUrl(route('client.inbox.setup'), 'connect') : null,
            'metaConfigIdWhatsapp'         => $metaCreds?->configIdWhatsapp() ?: null,
            'metaConfigIdSocial'           => $metaCreds?->configIdSocial() ?: null,
        ]);
    }

    /**
     * DEPRECATED Facebook-Login Instagram connect removed — Instagram connects
     * exclusively via Business Login for Instagram (instagramLoginConnect /
     * instagramManualToken below), which needs no Facebook Page.
     */

    public function embeddedSignupMessenger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
        ]);

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        if (! CredentialResolver::system()->meta()?->appId()) {
            return response()->json(['message' => 'Meta App credentials are not configured. Please ask your administrator to configure them in Admin → Integrations → Meta App.'], 422);
        }

        // Ensure the Meta App delivers `page` (Messenger) webhook events to our
        // endpoint. Without this app-level subscription Meta has no callback URL for
        // the page object, so inbound Messenger messages never reach the server.
        $this->registerMessengerAppWebhook();

        $accessToken = $this->exchangeCodeForToken($validated['code']);
        if (! $accessToken) {
            return response()->json(['message' => 'Failed to exchange authorization code with Meta.'], 422);
        }

        $longToken = $this->exchangeForLongLivedToken($accessToken);

        $pagesRes = Http::withToken($longToken)
            ->get('https://graph.facebook.com/v20.0/me/accounts', [
                'fields' => 'id,name,access_token',
                'limit'  => 50,
            ]);

        if (! $pagesRes->successful()) {
            Log::warning('Messenger embedded signup: pages fetch failed', [
                'workspace_id' => $workspaceId,
                'response'     => $pagesRes->json(),
            ]);

            return response()->json(['message' => 'Could not fetch your Facebook pages: ' . ($pagesRes->json('error.message') ?? 'unknown error')], 422);
        }

        $pages     = $pagesRes->json('data', []);
        $connected = 0;

        Log::info('Messenger embedded signup: pages fetched', [
            'workspace_id' => $workspaceId,
            'page_count'   => count($pages),
            'pages'        => collect($pages)->map(fn ($p) => [
                'id'   => $p['id'] ?? null,
                'name' => $p['name'] ?? null,
            ])->all(),
        ]);

        foreach ($pages as $page) {
            $pageId    = (string) ($page['id'] ?? '');
            $pageName  = $page['name'] ?? $pageId;
            $pageToken = $page['access_token'] ?? null;

            if (! $pageId) {
                continue;
            }

            // A PAGE access token is mandatory: it is what authorises send() and the
            // User Profile API (name/picture). If /me/accounts didn't include one,
            // fetch it explicitly. Never fall back to the user token — a user token
            // cannot resolve page-scoped PSIDs and yields Graph error 100.
            if (! $pageToken) {
                $tokenRes  = Http::withToken($longToken)
                    ->get("https://graph.facebook.com/v20.0/{$pageId}", ['fields' => 'access_token']);
                $pageToken = $tokenRes->json('access_token');
            }

            if (! $pageToken) {
                Log::warning('Messenger embedded signup: no page access token — page skipped', [
                    'workspace_id' => $workspaceId,
                    'page_id'      => $pageId,
                    'page_name'    => $pageName,
                ]);

                continue;
            }

            // Subscribe the page to Messenger webhooks
            $this->subscribePageToMessenger($pageId, $pageToken);

            $existing = ChannelAccount::where('workspace_id', $workspaceId)
                ->where('channel', 'messenger')
                ->whereJsonContains('meta_json->page_id', $pageId)
                ->first();
            $alreadyExists = $existing !== null;

            if ($existing) {
                // Update through the model instance (NOT the query builder) so the
                // `encrypted:array` cast runs and the page token is stored encrypted.
                // A query-builder update() bypasses casts and corrupts credentials,
                // which then fail to decrypt — breaking send() and the profile fetch.
                // Merge meta_json so an assigned ai_chatbot_id is preserved.
                $existing->update([
                    'credentials' => ['page_access_token' => $pageToken],
                    'meta_json'   => array_merge($existing->meta_json ?? [], ['page_id' => $pageId]),
                    'status'      => 'active',
                ]);
            } else {
                ChannelAccount::create([
                    'workspace_id' => $workspaceId,
                    'channel'      => 'messenger',
                    'provider'     => 'meta',
                    'display_name' => mb_substr((string) $pageName, 0, 128),
                    'credentials'  => ['page_access_token' => $pageToken],
                    'meta_json'    => ['page_id' => $pageId],
                    'status'       => 'active',
                ]);
            }

            Log::info('Messenger embedded signup: account connected', [
                'workspace_id' => $workspaceId,
                'page_id'      => $pageId,
                'reconnect'    => $alreadyExists,
            ]);

            $connected++;
        }

        if ($connected === 0) {
            return response()->json([
                'message' => 'No Facebook Pages found on your account. Make sure you manage at least one Facebook Page.',
            ], 422);
        }

        return response()->json(['success' => true, 'connected' => $connected]);
    }

    private function logMeta(string $message, array $context = []): void
    {
        try {
            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/meta.log'),
            ])->info($message, $context);
        } catch (\Throwable $e) {
            Log::warning("Meta Log fallback: {$message}", $context);
        }
    }

    private function exchangeCodeForToken(string $code): ?string
    {
        $meta = CredentialResolver::system()->meta();
        if (! $meta?->appId() || ! $meta?->appSecret()) {
            $this->logMeta('Code exchange failed: Meta App credentials not configured.');
            return null;
        }

        // FB.login() supplies this page as fallback_redirect_uri. Meta binds the
        // returned code to that full URL (including the path), not to APP_URL.
        $redirectUri = route('client.inbox.setup');

        $tokenParams = [
            'client_id'     => $meta->appId(),
            'client_secret' => $meta->appSecret(),
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
        ];

        $this->logMeta('Attempting Meta code exchange (Instagram/Messenger)', [
            'client_id'        => $meta->appId(),
            'config_id_social' => $meta->configIdSocial(),
            'redirect_uri'     => $redirectUri,
            'code_length'      => strlen($code),
            'code_hash'        => substr(hash('sha256', $code), 0, 12),
        ]);

        $res = Http::get('https://graph.facebook.com/v20.0/oauth/access_token', $tokenParams);

        if ((! $res->successful() || ! $res->json('access_token')) && isset($tokenParams['redirect_uri'])) {
            $this->logMeta('Retrying code exchange without redirect_uri...');
            unset($tokenParams['redirect_uri']);
            $res = Http::get('https://graph.facebook.com/v20.0/oauth/access_token', $tokenParams);
        }

        if (! $res->successful() || ! $res->json('access_token')) {
            $this->logMeta('Meta code exchange failed', [
                'status' => $res->status(),
                'response' => $res->json() ?: $res->body(),
            ]);
            return null;
        }

        $this->logMeta('Meta code exchange successful.');
        return $res->json('access_token');
    }

    private function exchangeForLongLivedToken(string $shortToken): string
    {
        $meta = CredentialResolver::system()->meta();
        if (! $meta?->appId() || ! $meta?->appSecret()) {
            return $shortToken;
        }

        $res = Http::get('https://graph.facebook.com/v20.0/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $meta->appId(),
            'client_secret'     => $meta->appSecret(),
            'fb_exchange_token' => $shortToken,
        ]);

        return ($res->successful() && $res->json('access_token')) ? $res->json('access_token') : $shortToken;
    }

    /**
     * Register the app-level `page` (Messenger) webhook subscription so Meta knows
     * which callback URL to deliver Messenger events to. Uses the App Access Token
     * ({app_id}|{app_secret}) and points at our /webhooks/meta/{verify_token}
     * endpoint. Idempotent: re-registering the same URL/fields is a no-op on Meta.
     * Mirrors registerInstagramAppWebhook() — without it inbound Messenger messages
     * never reach the server (no app-level callback for the page object).
     */
    private function registerMessengerAppWebhook(): void
    {
        $meta        = CredentialResolver::system()->meta();
        $appId       = $meta?->appId();
        $appSecret   = $meta?->appSecret();
        $verifyToken = $meta?->verifyToken();

        if (! $appId || ! $appSecret || ! $verifyToken) {
            Log::warning('Messenger embedded signup: cannot register app webhook — missing app id/secret/verify token', [
                'has_app_id'       => (bool) $appId,
                'has_app_secret'   => (bool) $appSecret,
                'has_verify_token' => (bool) $verifyToken,
            ]);

            return;
        }

        $callbackUrl = route('webhooks.meta.receive', ['token' => $verifyToken]);

        try {
            $res = Http::post("https://graph.facebook.com/v20.0/{$appId}/subscriptions", [
                'access_token' => $appId . '|' . $appSecret,
                'object'       => 'page',
                'callback_url' => $callbackUrl,
                'verify_token' => $verifyToken,
                'fields'       => 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads',
            ]);

            if (! $res->successful()) {
                Log::warning('Messenger embedded signup: app webhook registration failed', [
                    'callback_url' => $callbackUrl,
                    'status'       => $res->status(),
                    'response'     => $res->json(),
                ]);

                return;
            }

            Log::info('Messenger embedded signup: app webhook registered', [
                'callback_url' => $callbackUrl,
                'response'     => $res->json(),
            ]);

            // Read back what Meta actually stored so we can confirm the page object
            // has our callback URL and is marked active.
            $check = Http::get("https://graph.facebook.com/v20.0/{$appId}/subscriptions", [
                'access_token' => $appId . '|' . $appSecret,
            ]);
            Log::info('Messenger embedded signup: app subscriptions snapshot', [
                'status'   => $check->status(),
                'response' => $check->json(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Messenger embedded signup: app webhook registration exception', [
                'callback_url' => $callbackUrl,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    private function subscribePageToMessenger(string $pageId, string $pageToken): void
    {
        if ($pageId === '') {
            Log::warning('Messenger embedded signup: cannot subscribe — empty page id');

            return;
        }

        try {
            $res = Http::withToken($pageToken)
                ->post("https://graph.facebook.com/v20.0/{$pageId}/subscribed_apps", [
                    'subscribed_fields' => 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads',
                ]);

            if (! $res->successful()) {
                Log::warning('Messenger embedded signup: page subscription failed', [
                    'page_id'  => $pageId,
                    'status'   => $res->status(),
                    'response' => $res->json(),
                ]);

                return;
            }

            Log::info('Messenger embedded signup: page subscribed for messaging', [
                'page_id'  => $pageId,
                'response' => $res->json(),
            ]);

            // Read back the page's subscribed_apps so we can confirm which fields
            // (must include "messages") are actually active for our app.
            $check = Http::withToken($pageToken)
                ->get("https://graph.facebook.com/v20.0/{$pageId}/subscribed_apps");
            Log::info('Messenger embedded signup: page subscribed_apps snapshot', [
                'page_id'  => $pageId,
                'status'   => $check->status(),
                'response' => $check->json(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Messenger embedded signup: page subscription exception', [
                'page_id' => $pageId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Business Login for Instagram: the redirect-back handler. Setup.jsx detects
     * ?code= on the Setup page and POSTs it here. Exchanges the code, upgrades to
     * a 60-day token, resolves the account via /me (data-array shape) and stores
     * the connection with auth_type='instagram_login'. NO Facebook Page involved.
     */
    public function instagramLoginConnect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
        ]);

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $meta = CredentialResolver::system()->meta();
        if (! $meta?->igAppId() || ! $meta?->igAppSecret()) {
            return response()->json(['message' => 'Instagram App credentials are not configured. Ask your administrator to fill Instagram App ID/Secret in Admin → Integrations → Meta App.'], 422);
        }

        $auth = app(\App\Modules\Instagram\Services\InstagramAuthService::class);

        $short = $auth->exchangeCode($validated['code'], route('client.inbox.setup'));
        if (! $short) {
            return response()->json(['message' => 'Failed to exchange the authorization code with Instagram.'], 422);
        }

        $long = $auth->exchangeForLongLived($short['access_token']);
        $token = $long['access_token'] ?? $short['access_token'];
        $expiresIn = $long['expires_in'] ?? 3600;

        $account = $auth->fetchAccount($token);
        if (! $account) {
            return response()->json(['message' => 'Connected, but could not read your Instagram account details. Please retry.'], 422);
        }

        $connected = $this->persistInstagramLoginAccount($workspaceId, $account, $token, $expiresIn);

        if ($connected === 0) {
            return response()->json(['message' => 'Could not persist the Instagram connection. Check the Instagram logs.'], 422);
        }

        return response()->json(['success' => true, 'connected' => $connected, 'username' => $account['username']]);
    }

    /**
     * Manual token path: a 60-day token generated via App Dashboard →
     * Generate token. Lets a client connect without any OAuth flow.
     */
    public function instagramManualToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'access_token' => ['required', 'string', 'min:20'],
        ]);

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $meta = CredentialResolver::system()->meta();
        if (! $meta?->igAppId() || ! $meta?->igAppSecret()) {
            return response()->json(['message' => 'Instagram App credentials are not configured. Ask your administrator to fill Instagram App ID/Secret in Admin → Integrations → Meta App.'], 422);
        }

        $auth = app(\App\Modules\Instagram\Services\InstagramAuthService::class);

        // The dashboard token is already long-lived — validate it by fetching /me.
        $account = $auth->fetchAccount($validated['access_token']);
        if (! $account) {
            return response()->json(['message' => 'Token rejected by Instagram — make sure it was generated for YOUR Instagram professional account and has not expired.'], 422);
        }

        $connected = $this->persistInstagramLoginAccount($workspaceId, $account, $validated['access_token'], 5184000);

        if ($connected === 0) {
            return response()->json(['message' => 'Could not persist the Instagram connection. Check the Instagram logs.'], 422);
        }

        return response()->json(['success' => true, 'connected' => $connected, 'username' => $account['username']]);
    }

    /**
     * Shared persistence for both IG-login connect paths: channel_accounts +
     * instagram_accounts rows with auth_type='instagram_login', automatic
     * webhook registration and best-effort account field subscription.
     * NO page subscription — this flow has no Facebook Page.
     */
    private function persistInstagramLoginAccount(int $workspaceId, array $account, string $token, int $expiresIn): int
    {
        $igId = $account['user_id'];
        $name = $account['username'] ?? ($account['name'] ?? $igId);

        $metaJson = [
            'instagram_page_id'    => $igId,
            'instagram_account_id' => $igId,
            'auth_type'            => 'instagram_login',
            'token_expires_at'     => now()->addSeconds($expiresIn)->toIso8601String(),
            'token_refreshed_at'   => now()->toIso8601String(),
        ];

        $existing = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'instagram')
            ->whereJsonContains('meta_json->instagram_page_id', $igId)
            ->first();

        if ($existing) {
            $existing->update([
                'credentials' => ['access_token' => $token, 'instagram_account_id' => $igId],
                'meta_json'   => array_merge($existing->meta_json ?? [], $metaJson),
                'status'      => 'active',
            ]);
        } else {
            ChannelAccount::create([
                'workspace_id' => $workspaceId,
                'channel'      => 'instagram',
                'provider'     => 'meta',
                'display_name' => mb_substr((string) $name, 0, 128),
                'credentials'  => ['access_token' => $token, 'instagram_account_id' => $igId],
                'meta_json'    => $metaJson,
                'status'       => 'active',
            ]);
        }

        // Comment-automation module row — page_token carries the IG User token.
        \App\Modules\Instagram\Models\InstagramAccount::updateOrCreate(
            ['workspace_id' => $workspaceId, 'ig_user_id' => $igId],
            [
                'username'     => $account['username'] ?? null,
                'display_name' => $account['name'] ?? ($account['username'] ?? $igId),
                'page_id'      => null,
                'page_token'   => $token,
                'status'       => 'active',
                'meta_json'    => [
                    'connected_at'       => now()->toIso8601String(),
                    'auth_type'          => 'instagram_login',
                    'token_expires_at'   => now()->addSeconds($expiresIn)->toIso8601String(),
                    'token_refreshed_at' => now()->toIso8601String(),
                    'connected_via'      => 'instagram_login',
                ],
            ],
        );

        // Automatic webhook registration (same shared registrar the FB flow uses).
        \App\Modules\Shared\Services\MetaWebhookRegistrar::registerInstagramObject();

        // Best-effort account-level field subscription over the IG host.
        try {
            $moduleAccount = \App\Modules\Instagram\Models\InstagramAccount::where('workspace_id', $workspaceId)->where('ig_user_id', $igId)->first();
            if ($moduleAccount) {
                app(\App\Modules\Instagram\Services\InstagramGraphClient::class)->subscribeAccountFields($moduleAccount);
            }
        } catch (\Throwable $e) {
            Log::warning('ig_login: account field subscription failed (webhooks may still deliver)', [
                'ig_id' => $igId,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('ig_login: account connected', [
            'workspace_id' => $workspaceId,
            'ig_user_id'   => $igId,
            'username'     => $account['username'] ?? null,
        ]);

        return 1;
    }
    public function assignChatbot(Request $request, ChannelAccount $channelAccount): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $channelAccount->workspace_id === (int) $workspaceId, 403);

        $validated = $request->validate([
            'chatbot_id' => ['nullable', 'integer'],
        ]);

        $chatbotId = $validated['chatbot_id'] ?? null;

        if ($chatbotId !== null) {
            $exists = AiChatbot::where('id', $chatbotId)
                ->where('workspace_id', $workspaceId)
                ->where('enabled', true)
                ->exists();
            abort_unless($exists, 422, 'Chatbot not found or not enabled.');
        }

        $meta = $channelAccount->meta_json ?? [];
        if ($chatbotId === null) {
            unset($meta['ai_chatbot_id']);
        } else {
            $meta['ai_chatbot_id'] = $chatbotId;
        }
        $channelAccount->update(['meta_json' => $meta]);

        $label = $chatbotId ? 'Chatbot assigned.' : 'Chatbot removed.';

        return back()->with('success', $label);
    }

    public function destroy(Request $request, ChannelAccount $channelAccount): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        abort_unless((int) $channelAccount->workspace_id === (int) $workspaceId, 403);
        abort_unless(in_array($channelAccount->channel, ['instagram', 'messenger'], true), 403);

        // Disconnect here also pauses the comment-automation module for that
        // account (its automations stop firing on a disconnected account).
        if ($channelAccount->channel === 'instagram') {
            $igId = $channelAccount->meta_json['instagram_page_id']
                ?? $channelAccount->meta_json['instagram_account_id']
                ?? null;

            if ($igId && class_exists(\App\Modules\Instagram\Models\InstagramAccount::class)) {
                try {
                    \App\Modules\Instagram\Models\InstagramAccount::where('workspace_id', $channelAccount->workspace_id)
                        ->where('ig_user_id', $igId)
                        ->update(['status' => 'disconnected']);
                } catch (\Throwable $e) {
                    Log::warning('Instagram embedded signup: module account disconnect sync failed', [
                        'ig_id' => $igId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $channelAccount->delete();

        return back()->with('success', 'Account disconnected.');
    }
}

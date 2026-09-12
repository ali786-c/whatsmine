<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Instagram\Models\InstagramAccount;
use App\Modules\Instagram\Services\InstagramGraphClient;
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

        return Inertia::render('Instagram/Setup', [
            'accounts' => InstagramAccount::where('workspace_id', $workspaceId)
                ->get(['id', 'ig_user_id', 'username', 'display_name', 'page_id', 'status', 'meta_json', 'created_at']),
            'automationsCount' => \App\Modules\Instagram\Models\CommentAutomation::where('workspace_id', $workspaceId)->count(),
            'metaAppId' => $meta?->appId() ?: null,
            'metaConfigIdSocial' => $meta?->configIdSocial() ?: null,
            'webhookUrl' => $meta?->verifyToken() ? route('webhooks.instagram.receive', ['token' => $meta->verifyToken()]) : null,
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

        // Point the app-level `instagram` object at THIS module's endpoint with the
        // full field set (superset of the Inbox module's registration — `comments`
        // added; messaging fields identical so Inbox behaviour is unchanged).
        if (filled($meta->verifyToken())) {
            app(InstagramGraphClient::class)->registerAppSubscription(
                $meta->appId(),
                (string) $meta->appSecret(),
                (string) $meta->verifyToken(),
                route('webhooks.instagram.receive', ['token' => $meta->verifyToken()]),
            );
        }

        $pagesRes = Http::withToken($longToken)
            ->get("https://graph.facebook.com/{$this->apiVersion()}/me/accounts", [
                'fields' => 'id,name,access_token,instagram_business_account{id,name,username}',
                'limit' => 50,
            ]);

        if (! $pagesRes->successful()) {
            Log::warning('instagram_module: pages fetch failed', ['response' => $pagesRes->json()]);

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
                Log::warning('instagram_module: account field subscription failed', [
                    'ig_user_id' => $igId,
                    'error' => $e->getMessage(),
                ]);
            }

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
            Log::warning('instagram_module: code exchange failed', ['response' => $res->json() ?: $res->body()]);

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

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}

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

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }
}

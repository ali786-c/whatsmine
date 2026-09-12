<?php

namespace App\Modules\Shared\Services;

use App\Modules\Integrations\Services\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * The SINGLE registrar for the Meta `instagram` webhook object.
 *
 * Why this exists: Meta allows ONE callback URL per webhook object app-wide,
 * and every POST /{app_id}/subscriptions call REPLACES the entire field list
 * for that object. Two features share the `instagram` object:
 *
 *  - Inbox Instagram DMs            (fields: messages, messaging_postbacks, …)
 *  - Instagram comment automation   (fields: comments)
 *
 * If each module registered its own callback/fields, whichever connect flow
 * ran last would silently disable the other. Every registration now goes
 * through here, so the outcome is always identical no matter which flow runs:
 *
 *  callback → /webhooks/instagram/{verify_token}
 *  fields   → comments + messages + messaging_postbacks + message_reactions
 *
 * The Instagram module's endpoint owns that URL and forwards `messaging`
 * events into the Inbox pipeline, so both features work from one subscription.
 *
 * If the Instagram module is ever deleted, its route disappears and this
 * registrar automatically falls back to the Inbox-only callback/field set on
 * the next connect — the deletion drill keeps working.
 */
class MetaWebhookRegistrar
{
    /** Field set when the Instagram module is present (superset — add new fields here). */
    public const INSTAGRAM_FIELDS = 'comments,messages,messaging_postbacks,message_reactions';

    /** Field set when only the Inbox module exists (module deleted / route gone). */
    public const INBOX_ONLY_FIELDS = 'messages,messaging_postbacks,message_reactions';

    /**
     * Register (idempotently) the app-level `instagram` webhook object using the
     * App Access Token ({app_id}|{app_secret}). Safe to call from any connect flow.
     */
    public static function registerInstagramObject(): void
    {
        $meta = CredentialResolver::system()->meta();
        $appId = $meta?->appId();
        $appSecret = $meta?->appSecret();
        $verifyToken = $meta?->verifyToken();

        if (! $appId || ! $appSecret || ! $verifyToken) {
            Log::warning('meta_webhook_registrar: cannot register instagram object — missing app id/secret/verify token', [
                'has_app_id' => (bool) $appId,
                'has_app_secret' => (bool) $appSecret,
                'has_verify_token' => (bool) $verifyToken,
            ]);

            return;
        }

        // The Instagram module owns the callback when its route exists; otherwise
        // fall back to the Inbox endpoint so a module deletion self-heals.
        $moduleRouteExists = Route::has('webhooks.instagram.receive');

        $callbackUrl = $moduleRouteExists
            ? url('/webhooks/instagram/'.$verifyToken)
            : url('/webhooks/meta/'.$verifyToken);

        $fields = $moduleRouteExists ? self::INSTAGRAM_FIELDS : self::INBOX_ONLY_FIELDS;

        try {
            $res = Http::post("https://graph.facebook.com/v20.0/{$appId}/subscriptions", [
                'access_token' => $appId.'|'.$appSecret,
                'object' => 'instagram',
                'callback_url' => $callbackUrl,
                'verify_token' => $verifyToken,
                'fields' => $fields,
            ]);

            if (! $res->successful()) {
                Log::warning('meta_webhook_registrar: instagram object registration failed', [
                    'callback_url' => $callbackUrl,
                    'status' => $res->status(),
                    'response' => $res->json(),
                ]);

                return;
            }

            Log::info('meta_webhook_registrar: instagram object registered', [
                'callback_url' => $callbackUrl,
                'fields' => $fields,
            ]);

            // Read back what Meta actually stored so drift is visible in logs.
            $check = Http::get("https://graph.facebook.com/v20.0/{$appId}/subscriptions", [
                'access_token' => $appId.'|'.$appSecret,
            ]);
            Log::info('meta_webhook_registrar: app subscriptions snapshot', [
                'status' => $check->status(),
                'response' => $check->json(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('meta_webhook_registrar: instagram object registration exception', [
                'callback_url' => $callbackUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

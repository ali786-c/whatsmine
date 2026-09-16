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
     * Field set for the INSTAGRAM app (Business Login for Instagram). The
     * Instagram-login webhook docs list messaging_reactions/messaging_seen
     * naming there — we subscribe only the fields the pipeline actually
     * consumes to avoid a rejected registration over an unknown field.
     */
    public const INSTAGRAM_APP_FIELDS = 'comments,messages,messaging_postbacks';

    /**
     * Read back the INSTAGRAM app's instagram subscription (or null when the
     * object is not subscribed / credentials missing). Used by diagnostics.
     *
     * @return array<string, mixed>|null
     */
    public static function verifyInstagramAppObject(): ?array
    {
        $meta = CredentialResolver::system()->meta();
        $igAppId = $meta?->igAppId();
        $igAppSecret = $meta?->igAppSecret();

        if (! $igAppId || ! $igAppSecret) {
            return null;
        }

        try {
            $check = Http::get("https://graph.facebook.com/v20.0/{$igAppId}/subscriptions", [
                'access_token' => $igAppId.'|'.$igAppSecret,
            ]);

            if (! $check->successful()) {
                return null;
            }

            foreach ((array) $check->json('data', []) as $subscription) {
                if (($subscription['object'] ?? '') === 'instagram') {
                    return (array) $subscription;
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

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

            // Also register on the INSTAGRAM app (Business Login for Instagram)
            // — events for IG-Login connections are delivered from that app's
            // subscription. Best-effort; the manual dashboard option still works.
            self::registerInstagramAppObject();

            // Read back what Meta actually stored so drift is visible in logs
            // AND fixed the next time any connect flow runs.
            self::auditInstagramObject();
        } catch (\Throwable $e) {
            Log::warning('meta_webhook_registrar: instagram object registration exception', [
                'callback_url' => $callbackUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Register the instagram webhook object on the INSTAGRAM app (the one from
     * Instagram → API setup with Instagram login → Business login settings).
     * Same callback URL and verify token as the classic app; Meta signs those
     * deliveries with the Instagram app secret, which the webhook endpoint's
     * dual-signature check accepts.
     */
    public static function registerInstagramAppObject(): ?array
    {
        $meta = CredentialResolver::system()->meta();
        $igAppId = $meta?->igAppId();
        $igAppSecret = $meta?->igAppSecret();
        $verifyToken = $meta?->verifyToken();

        if (! $igAppId || ! $igAppSecret || ! $verifyToken) {
            return null;
        }

        $moduleRouteExists = Route::has('webhooks.instagram.receive');
        $callbackUrl = $moduleRouteExists
            ? url('/webhooks/instagram/'.$verifyToken)
            : url('/webhooks/meta/'.$verifyToken);
        $fields = self::INSTAGRAM_APP_FIELDS;

        try {
            $res = Http::post("https://graph.facebook.com/v20.0/{$igAppId}/subscriptions", [
                'access_token' => $igAppId.'|'.$igAppSecret,
                'object' => 'instagram',
                'callback_url' => $callbackUrl,
                'verify_token' => $verifyToken,
                'fields' => $fields,
            ]);

            if (! $res->successful()) {
                Log::warning('meta_webhook_registrar: instagram-app object registration failed', [
                    'ig_app_id' => $igAppId,
                    'status' => $res->status(),
                    'response' => $res->json(),
                ]);

                return ['ok' => false, 'error' => (string) ($res->json('error.message') ?? ('HTTP '.$res->status()))];
            }

            Log::info('meta_webhook_registrar: instagram-app object registered', [
                'ig_app_id' => $igAppId,
                'callback_url' => $callbackUrl,
                'fields' => $fields,
            ]);

            return ['ok' => true, 'callback_url' => $callbackUrl, 'fields' => $fields];
        } catch (\Throwable $e) {
            Log::warning('meta_webhook_registrar: instagram-app object registration exception', [
                'ig_app_id' => $igAppId,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify the app-level `instagram` subscription matches what this registrar
     * enforces. Returns the subscription Meta actually has (or null when the
     * object is not subscribed at all — a common silent killer of ALL inbound
     * DMs/comments). Call after connecting an account or from diagnostics.
     *
     * @return array<string, mixed>|null
     */
    public static function verifyInstagramObject(): ?array
    {
        $meta = CredentialResolver::system()->meta();
        $appId = $meta?->appId();
        $appSecret = $meta?->appSecret();

        if (! $appId || ! $appSecret) {
            return null;
        }

        try {
            $check = Http::get("https://graph.facebook.com/v20.0/{$appId}/subscriptions", [
                'access_token' => $appId.'|'.$appSecret,
            ]);

            if (! $check->successful()) {
                Log::warning('meta_webhook_registrar: subscriptions read failed', [
                    'status' => $check->status(),
                    'response' => $check->json(),
                ]);

                return null;
            }

            foreach ((array) $check->json('data', []) as $subscription) {
                if (($subscription['object'] ?? '') === 'instagram') {
                    return (array) $subscription;
                }
            }

            return null; // object not subscribed at all
        } catch (\Throwable $e) {
            Log::warning('meta_webhook_registrar: subscriptions read exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Normalize the `fields` list from Meta's subscription responses.
     * The app-level GET /{app_id}/subscriptions endpoint returns fields as a
     * list of OBJECTS ({name, version}); tolerate plain strings too.
     *
     * @param  mixed  $raw
     * @return array<int, string>
     */
    public static function normalizeFields(mixed $raw): array
    {
        $fields = [];

        foreach ((array) $raw as $field) {
            if (is_array($field)) {
                $name = $field['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $fields[] = $name;
                }
            } elseif (is_string($field) && $field !== '') {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Log the stored instagram subscription and WARN when it has drifted from
     * the expected callback/fields (drift = one feature silently disabled).
     */
    public static function auditInstagramObject(): void
    {
        $moduleRouteExists = Route::has('webhooks.instagram.receive');
        $expectedCallback = rtrim(url($moduleRouteExists ? '/webhooks/instagram' : '/webhooks/meta'), '/').'/';
        $expectedFields = $moduleRouteExists
            ? explode(',', self::INSTAGRAM_FIELDS)
            : explode(',', self::INBOX_ONLY_FIELDS);

        $subscription = self::verifyInstagramObject();

        if ($subscription === null) {
            Log::warning('meta_webhook_registrar: NO instagram subscription exists on the app — DMs and comments will never arrive', [
                'expected_callback_prefix' => $expectedCallback,
            ]);

            return;
        }

        $storedFields = self::normalizeFields($subscription['fields'] ?? []);
        sort($storedFields);
        sort($expectedFields);

        $callbackDrifted = ! str_starts_with((string) ($subscription['callback_url'] ?? ''), $expectedCallback);
        $fieldsDrifted = $storedFields !== $expectedFields;

        Log::info('meta_webhook_registrar: app subscriptions snapshot', [
            'callback_url' => $subscription['callback_url'] ?? null,
            'fields' => $storedFields,
            'callback_matches' => ! $callbackDrifted,
            'fields_match' => ! $fieldsDrifted,
        ]);

        if ($callbackDrifted || $fieldsDrifted) {
            Log::warning('meta_webhook_registrar: instagram subscription DRIFTED — inbound Instagram events are impaired', [
                'stored_callback' => $subscription['callback_url'] ?? null,
                'stored_fields' => $storedFields,
                'expected_callback_prefix' => $expectedCallback,
                'expected_fields' => $expectedFields,
            ]);
        }
    }
}

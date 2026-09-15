<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Concerns\FlushesWebhookResponse;
use App\Http\Controllers\Controller;
use App\Modules\Instagram\Jobs\ProcessInstagramCommentJob;
use App\Modules\Instagram\Jobs\ProcessInstagramDmJob;
use App\Modules\Instagram\Services\InstagramLog;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Services\WebhookIdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The module's own endpoint for Meta's `instagram` webhook object. Deliberately
 * separate from the Inbox module's /webhooks/meta endpoint so the two
 * integrations can be enabled/removed independently.
 *
 * Note: Meta allows ONE callback URL per webhook object app-wide. The module's
 * connect flow registers THIS URL for object `instagram` with the full field set
 * (comments + messaging fields). `messaging` events are forwarded into the
 * Inbox pipeline (class-guarded) so Instagram DMs keep flowing to the Inbox
 * exactly as before — one-directional, best-effort, never the reverse.
 */
class InstagramWebhookController extends Controller
{
    use FlushesWebhookResponse;

    public function verify(Request $request, string $token): Response
    {
        $meta = CredentialResolver::system()->meta();
        $verifyToken = $meta?->verifyToken() ?? '';

        if ($verifyToken !== ''
            && hash_equals($verifyToken, (string) $token)
            && $request->input('hub_mode') === 'subscribe') {
            return response($request->input('hub_challenge', ''), 200);
        }

        abort(403);
    }

    public function receive(Request $request, string $token): JsonResponse
    {
        $meta = CredentialResolver::system()->meta();
        $verifyToken = $meta?->verifyToken() ?? '';

        if ($verifyToken === '' || ! hash_equals($verifyToken, (string) $token)) {
            InstagramLog::webhook('warning', 'instagram_module.webhook.invalid_token', ['ip' => $request->ip()]);

            abort(403);
        }

        // Dual-signature: webhooks registered through the classic Meta app are
        // signed with the FB app secret; Instagram-Login registrations are signed
        // with the Instagram app secret. Accept either — both secrets staying
        // configured is the normal state after the Instagram Login migration.
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $secrets = array_values(array_filter([
            $meta->appSecret(),
            $meta->igAppSecret(),
        ]));

        if ($secrets !== []) {
            $valid = false;
            foreach ($secrets as $secret) {
                $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $secret);
                if ($expected !== '' && hash_equals($expected, $signature)) {
                    $valid = true;
                    break;
                }
            }

            if (! $valid) {
                InstagramLog::webhook('warning', 'instagram_module.webhook.signature_mismatch', ['ip' => $request->ip()]);

                abort(401, 'Invalid signature');
            }
        } elseif (app()->environment('production')) {
            InstagramLog::webhook('critical', 'instagram_module.webhook.no_secret', ['ip' => $request->ip()]);

            abort(401, 'App secret not configured');
        }

        if ((string) $request->input('object') !== 'instagram') {
            return response()->json(['status' => 'ok']);
        }

        $queue = (string) config('instagram.queue', 'instagram');
        $comments = [];
        $dmEvents = [];

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ($this->normalizeCommentValues($entry) as $value) {
                $comments[] = ['entry_id' => (string) ($entry['id'] ?? ''), 'value' => $value];
            }

            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                $dmEvents[] = ['entry_id' => (string) ($entry['id'] ?? ''), 'event' => $event];
            }
        }

        $idempotency = app(WebhookIdempotencyService::class);

        $newComments = array_values(array_filter($comments, fn (array $c) => $this->isNew(
            $idempotency,
            'ig_comment',
            (string) data_get($c, 'value.comment_id', '')
                ?: 'blob:'.hash('sha256', (string) json_encode($c['value'] ?? [], JSON_UNESCAPED_UNICODE)),
        )));

        $newDmEvents = array_values(array_filter($dmEvents, function (array $d) use ($idempotency): bool {
            $mid = data_get($d, 'event.message.mid');
            $eventId = $mid !== null ? (string) $mid : 'blob:'.hash('sha256', (string) json_encode($d['event'] ?? [], JSON_UNESCAPED_UNICODE));

            return $this->isNew($idempotency, 'ig_dm', $eventId);
        }));

        if ($newComments === [] && $newDmEvents === []) {
            return response()->json(['status' => 'ok']);
        }

        InstagramLog::webhook('info', 'instagram_module.webhook.dispatching', [
            'comments' => count($newComments),
            'dm_events' => count($newDmEvents),
        ]);

        return $this->flushWebhookOkThen(function () use ($newComments, $newDmEvents, $queue): void {
            foreach ($newComments as $c) {
                ProcessInstagramCommentJob::dispatch($c['entry_id'], $c['value'])->onQueue($queue);
            }

            foreach ($newDmEvents as $d) {
                ProcessInstagramDmJob::dispatch($d['entry_id'], $d['event'])->onQueue($queue);
            }
        });
    }

    private function isNew(WebhookIdempotencyService $idempotency, string $provider, string $eventId): bool
    {
        // No stable id at all → fail open so real events are never silently dropped.
        if ($eventId === '') {
            return true;
        }

        // Native ids and blob hashes (events without an id) both dedupe here.
        return $idempotency->isNewEvent($provider, $eventId);
    }

    /**
     * Extract comment values from both payload shapes Meta sends:
     *  - Facebook Login for Business: entry[].changes[] {field: "comments", value: {...}}
     *  - Business Login for Instagram: entry[].field === "comments" with value directly on entry.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCommentValues(array $entry): array
    {
        $values = [];

        foreach ((array) ($entry['changes'] ?? []) as $change) {
            if (($change['field'] ?? null) === 'comments' && isset($change['value'])) {
                $values[] = (array) $change['value'];
            }
        }

        if (($entry['field'] ?? null) === 'comments' && isset($entry['value'])) {
            $values[] = (array) $entry['value'];
        }

        return $values;
    }
}

<?php

namespace App\Modules\Instagram\Services;

use App\Modules\Instagram\Exceptions\InstagramGraphException;
use App\Modules\Instagram\Models\InstagramAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin Instagram Graph API client. All module Meta calls go through here so
 * error mapping and logging are consistent.
 *
 * HOST ROUTING (Instagram Login migration):
 *  - auth_type='instagram_login' (or no page_id) → graph.instagram.com + the
 *    IG User access token stored in page_token. NO Facebook Page involved.
 *  - legacy Facebook Login accounts → graph.facebook.com + Page token (unchanged).
 */
class InstagramGraphClient
{
    private function apiVersion(): string
    {
        return (string) config('instagram.api_version', 'v20.0');
    }

    /**
     * True when the account connected through Business Login for Instagram
     * (Instagram credentials, IG User token, graph.instagram.com).
     */
    public function isInstagramLogin(InstagramAccount $account): bool
    {
        if (($account->meta_json['auth_type'] ?? null) === 'instagram_login') {
            return true;
        }

        // No linked page => it cannot be a Facebook Login connection.
        return blank($account->page_id);
    }

    private function graphHost(InstagramAccount $account): string
    {
        return $this->isInstagramLogin($account) ? 'graph.instagram.com' : 'graph.facebook.com';
    }

    /**
     * The ONE private reply allowed per comment (Meta Private Replies API).
     * The recipient is the comment id — not an IGSID.
     *
     * @return array<string, mixed> e.g. {recipient_id, message_id}
     *
     * @throws InstagramGraphException
     */
    public function sendPrivateReply(InstagramAccount $account, string $commentId, string $text): array
    {
        return $this->post($account, "{$account->ig_user_id}/messages", [
            'recipient' => ['comment_id' => $commentId],
            'message' => ['text' => $this->limitText($text, 'private reply')],
        ]);
    }

    /**
     * A normal DM send to an IGSID. Only valid while the 24h window
     * (opened by the commenter's reply) is open. Optional quick replies
     * render as tappable buttons (used by the follow-gate loop).
     *
     * @param  array<int, array{content_type: string, title: string, payload: string}>|array<int, mixed>  $quickReplies
     * @return array<string, mixed>
     *
     * @throws InstagramGraphException
     */
    public function sendMessage(InstagramAccount $account, string $igsid, string $text, array $quickReplies = []): array
    {
        $message = ['text' => $this->limitText($text, 'DM')];

        if ($quickReplies !== []) {
            $message['quick_replies'] = $quickReplies;
        }

        return $this->post($account, "{$account->ig_user_id}/messages", [
            'recipient' => ['id' => $igsid],
            'message' => $message,
        ]);
    }

    /**
     * Generic template (carousel): 1–10 horizontally scrollable elements, each
     * with title (80 chars max), optional subtitle (80) and image_url, and up
     * to 3 web_url/postback buttons (Generic Template docs). Composed in the
     * Inbox Instagram template composer; no Meta approval is required.
     *
     * @param  array<int, array<string, mixed>>  $elements
     * @return array<string, mixed>
     *
     * @throws InstagramGraphException
     */
    public function sendGenericTemplate(InstagramAccount $account, string $igsid, array $elements): array
    {
        $elements = array_slice($elements, 0, 10);

        foreach ($elements as $i => $el) {
            $elements[$i]['title'] = mb_substr((string) ($el['title'] ?? ''), 0, 80);

            if (! empty($el['subtitle'])) {
                $elements[$i]['subtitle'] = mb_substr((string) $el['subtitle'], 0, 80);
            }

            if (! empty($el['buttons'])) {
                $elements[$i]['buttons'] = array_slice($el['buttons'], 0, 3);
            }
        }

        return $this->post($account, "{$account->ig_user_id}/messages", [
            'recipient' => ['id' => $igsid],
            'message' => [
                'attachment' => [
                    'type' => 'template',
                    'payload' => [
                        'template_type' => 'generic',
                        'elements' => array_values($elements),
                    ],
                ],
            ],
        ]);
    }

    /**
     * Button template: up-to-640-char text with 1–3 web_url/postback buttons
     * (Button Template docs). Postback taps arrive as messaging_postbacks,
     * which the webhook pipeline already records in the thread.
     *
     * @param  array<int, array{type: string, title: string, url?: string, payload?: string}>  $buttons
     * @return array<string, mixed>
     *
     * @throws InstagramGraphException
     */
    public function sendButtonTemplate(InstagramAccount $account, string $igsid, string $text, array $buttons): array
    {
        return $this->post($account, "{$account->ig_user_id}/messages", [
            'recipient' => ['id' => $igsid],
            'message' => [
                'attachment' => [
                    'type' => 'template',
                    'payload' => [
                        'template_type' => 'button',
                        'text' => $this->limitText($text, 'button template'),
                        'buttons' => array_values(array_slice($buttons, 0, 3)),
                    ],
                ],
            ],
        ]);
    }

    /**
     * Attachment send (image/video/file by public URL) — used for file deliveries.
     *
     * @return array<string, mixed>
     *
     * @throws InstagramGraphException
     */
    public function sendAttachment(InstagramAccount $account, string $igsid, string $type, string $url): array
    {
        $this->assertAttachmentSize($type, $url);

        return $this->post($account, "{$account->ig_user_id}/messages", [
            'recipient' => ['id' => $igsid],
            'message' => [
                'attachment' => [
                    'type' => $type, // image | video | file
                    'payload' => [
                        'url' => $url,
                        'is_reusable' => true,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Subscribe this IG account to the webhook fields the module needs —
     * account-level subscription on graph.instagram.com. The IG-Login flow has
     * no Facebook Page, so this replaces the old page subscribed_apps call.
     *
     * Docs (Instagram Platform → Webhooks → Enable Subscriptions): the account
     * must POST to "{ig_id}/subscribed_apps" with subscribed_fields —
     * a "/subscribed_fields" path does NOT exist (calls to it silently 404'd,
     * which is why connected accounts never received DMs).
     *
     * @return array<string, mixed>
     *
     * @throws InstagramGraphException
     */
    public function subscribeAccountFields(InstagramAccount $account): array
    {
        return $this->post($account, "{$account->ig_user_id}/subscribed_apps", [
            'subscribed_fields' => 'comments,messages,messaging_postbacks,message_reactions',
        ]);
    }

    /**
     * Recent posts/reels of the account — powers the automation builder's
     * post picker (per-post scoping via comment webhook media.id).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws InstagramGraphException
     */
    public function getRecentMedia(InstagramAccount $account, int $limit = 12): array
    {
        $url = "https://{$this->graphHost($account)}/{$this->apiVersion()}/{$account->ig_user_id}/media";

        try {
            $res = Http::withToken($account->page_token)
                ->acceptJson()
                ->timeout(30)
                ->get($url, [
                    'fields' => 'id,caption,media_product_type,media_url,thumbnail_url,permalink,timestamp',
                    'limit' => min(25, max(1, $limit)),
                ]);
        } catch (\Throwable $e) {
            throw new InstagramGraphException('Network error talking to Graph: '.$e->getMessage(), httpStatus: 0);
        }

        if (! $res->successful()) {
            $error = (array) $res->json('error', []);

            throw new InstagramGraphException(
                message: (string) ($error['message'] ?? $res->body()),
                graphErrorCode: isset($error['code']) ? (int) $error['code'] : null,
                httpStatus: $res->status(),
            );
        }

        return (array) $res->json('data', []);
    }

    /**
     * True when the IGSID follows this IG account — via the Graph User Profile
     * API field is_user_follow_business. Meta exposes that field only after the
     * user has messaged the account (DM consent); on ANY inconclusive outcome
     * (no consent yet, network error, permission, missing field) it returns
     * null so callers can fail open instead of punishing a real lead.
     *
     * @return bool|null true = follows · false = verified NOT following · null = unknown
     */
    public function doesUserFollow(InstagramAccount $account, string $igsid): ?bool
    {
        $url = "https://{$this->graphHost($account)}/{$this->apiVersion()}/{$igsid}";

        try {
            $res = Http::withToken($account->page_token)
                ->acceptJson()
                ->timeout(15)
                ->get($url, ['fields' => 'is_user_follow_business']);
        } catch (\Throwable $e) {
            InstagramLog::dm('warning', 'follow-status lookup failed (network) — failing open', ['igsid' => $igsid, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $res->successful()) {
            InstagramLog::dm('warning', 'follow-status lookup rejected — failing open', ['igsid' => $igsid, 'status' => $res->status(), 'error' => (string) $res->json('error.message', $res->body())]);

            return null;
        }

        $follows = $res->json('is_user_follow_business');

        if (! is_bool($follows)) {
            // Field absent (consent edge / older shape) reads as unknown, not false.
            InstagramLog::dm('warning', 'follow-status field absent — failing open', ['igsid' => $igsid, 'payload_keys' => array_keys((array) $res->json())]);

            return null;
        }

        InstagramLog::dm('info', 'follow-status resolved', ['igsid' => $igsid, 'follows' => $follows]);

        return $follows;
    }

    /**
     * Meta hard-caps DM text at 1000 UTF-8 bytes (Send Messages docs). A longer
     * text is REJECTED by Graph and fails the whole job with an opaque error —
     * truncate to a safe 998 bytes on a character boundary instead, with a log.
     */
    private function limitText(string $text, string $context): string
    {
        $bytes = strlen($text);

        if ($bytes <= 998) {
            return $text;
        }

        InstagramLog::warning('instagram text truncated to 1000-byte cap', ['context' => $context, 'original_bytes' => $bytes]);
        $truncated = substr($text, 0, 998);

        // Never leave a broken multi-byte character at the end.
        while ($truncated !== '' && ! mb_check_encoding($truncated, 'UTF-8')) {
            $truncated = substr($truncated, 0, -1);
        }

        return $truncated;
    }

    /**
     * Reject media the Graph API would bounce anyway: images > 8MB and
     * audio/video/files > 25MB (docs: Media types and specifications). A HEAD
     * probe keeps oversized deliveries from failing after the fact — the caller
     * sees the real reason instead of an opaque Graph error. Probe failure is
     * fail-open (send anyway) so a CDN that blocks HEAD cannot break deliveries.
     */
    private function assertAttachmentSize(string $type, string $url): void
    {
        $maxBytes = $type === 'image' ? 8 * 1024 * 1024 : 25 * 1024 * 1024;

        try {
            $head = Http::timeout(10)->head($url);
            $length = (int) ($head->header('Content-Length') ?? 0);
        } catch (\Throwable $e) {
            InstagramLog::warning('attachment size probe failed — sending anyway', ['url' => $url, 'error' => $e->getMessage()]);

            return;
        }

        if ($length > 0 && $length > $maxBytes) {
            throw new InstagramGraphException(
                'Attachment too large for Instagram ('.round($length / 1048576, 1).'MB > '.round($maxBytes / 1048576).'MB '.$type.' limit): '.$url
            );
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws InstagramGraphException
     */
    private function post(InstagramAccount $account, string $path, array $body): array
    {
        $url = "https://{$this->graphHost($account)}/{$this->apiVersion()}/{$path}";

        try {
            $res = Http::withToken($account->page_token)
                ->acceptJson()
                ->timeout(30)
                ->post($url, $body);
        } catch (\Throwable $e) {
            throw new InstagramGraphException('Network error talking to Graph: '.$e->getMessage(), httpStatus: 0);
        }

        if ($res->successful()) {
            return (array) $res->json();
        }

        $error = (array) $res->json('error', []);

        throw new InstagramGraphException(
            message: (string) ($error['message'] ?? $res->body()),
            graphErrorCode: isset($error['code']) ? (int) $error['code'] : null,
            graphErrorSubcode: isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
            httpStatus: $res->status(),
        );
    }
}

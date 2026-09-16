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
            'message' => ['text' => $text],
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
        $message = ['text' => $text];

        if ($quickReplies !== []) {
            $message['quick_replies'] = $quickReplies;
        }

        return $this->post($account, "{$account->ig_user_id}/messages", [
            'recipient' => ['id' => $igsid],
            'message' => $message,
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
     * Subscribe this IG account to the webhook fields the module needs
     * (per-account subscription; harmless if the platform ignores it).
     * IG-Login accounts subscribe on graph.instagram.com.
     *
     * @return array<string, mixed>
     *
     * @throws InstagramGraphException
     */
    public function subscribeAccountFields(InstagramAccount $account): array
    {
        return $this->post($account, "{$account->ig_user_id}/subscribed_fields", [
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

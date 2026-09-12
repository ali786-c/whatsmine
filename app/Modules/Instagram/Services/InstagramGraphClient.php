<?php

namespace App\Modules\Instagram\Services;

use App\Modules\Instagram\Exceptions\InstagramGraphException;
use App\Modules\Instagram\Models\InstagramAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin Instagram Graph API client (Facebook Login path — Page access token,
 * graph.facebook.com). All module Meta calls go through here so error mapping
 * and logging are consistent.
 */
class InstagramGraphClient
{
    private function apiVersion(): string
    {
        return (string) config('instagram.api_version', 'v20.0');
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
     * (opened by the commenter's reply) is open.
     *
     * @return array<string, mixed>
     *
     * @throws InstagramGraphException
     */
    public function sendMessage(InstagramAccount $account, string $igsid, string $text): array
    {
        return $this->post($account, "{$account->ig_user_id}/messages", [
            'recipient' => ['id' => $igsid],
            'message' => ['text' => $text],
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
        $url = "https://graph.facebook.com/{$this->apiVersion()}/{$account->ig_user_id}/media";

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
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws InstagramGraphException
     */
    private function post(InstagramAccount $account, string $path, array $body): array
    {
        $url = "https://graph.facebook.com/{$this->apiVersion()}/{$path}";

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

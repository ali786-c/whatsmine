<?php

namespace App\Modules\Shared\Services;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;

/**
 * Plan-governed limit on how many channel accounts a workspace may connect,
 * per channel. Keys live in Plan.limits JSON:
 *
 *   whatsapp_accounts     — Meta Cloud API numbers
 *   whatsapp_qr_accounts  — QR (Baileys) numbers — SEPARATE limit from Cloud API
 *   instagram_accounts    — Instagram professional accounts
 *   messenger_accounts    — Facebook Pages connected for Messenger
 *
 *   null => unlimited (default when the plan does not define the key)
 *   0    => no new connections at all
 *
 * The count covers ACTIVE + INACTIVE rows but a pending NEW connect does not
 * count until it persists — so a client cannot soft-lock themselves by opening
 * several signup tabs at once, and an 'error' account can be deleted to make
 * room like anywhere else.
 *
 * Enforcement points (every place a NEW channel account row is born):
 *   - WhatsappEmbeddedSignupController::store   (WhatsApp Cloud API)
 *   - WhatsappQRController::store / QrWebhookController (WhatsApp QR)
 *   - InboxSetupController::instagramLoginConnect / instagramManualToken
 *   - ConnectController::igLoginCallback        (module-side IG callback)
 *   - InboxSetupController::embeddedSignupMessenger (multi-page batch)
 *
 * Re-connects (an existing account being refreshed/re-authorised) are never
 * blocked — dedup happens before the guard in every flow.
 */
class ChannelLimitService
{
    /** Plan limit key + display label per logical channel. */
    public const CHANNEL_KEYS = [
        'whatsapp' => ['limit' => 'whatsapp_accounts', 'label' => 'WhatsApp (Cloud API)'],
        'whatsapp_qr' => ['limit' => 'whatsapp_qr_accounts', 'label' => 'WhatsApp QR'],
        'instagram' => ['limit' => 'instagram_accounts', 'label' => 'Instagram'],
        'messenger' => ['limit' => 'messenger_accounts', 'label' => 'Messenger'],
    ];

    /**
     * Map a channel_accounts.channel value to its logical channel — Cloud API
     * and QR each have their OWN limit bucket.
     */
    public static function logicalChannel(string $channel): string
    {
        return $channel; // 'whatsapp', 'whatsapp_qr', 'instagram', 'messenger'
    }

    public function __construct(private readonly ?int $workspaceId) {}

    /** Plan limit for the logical channel — null = unlimited. */
    public function limitFor(string $logicalChannel): ?int
    {
        $key = self::CHANNEL_KEYS[$logicalChannel]['limit'] ?? null;
        if ($key === null) {
            return null;
        }

        $workspace = Workspace::find($this->workspaceId);
        $plan = $workspace?->client?->activePlan();

        if (! $plan instanceof Plan) {
            return null; // no plan attached — never block on billing config gaps
        }

        return $plan->limitValue($key);
    }

    /** Current account count for the logical channel. */
    public function usedFor(string $logicalChannel): int
    {
        // All channel_accounts.channel values that roll up to this logical channel.
        $dbChannels = match ($logicalChannel) {
            'whatsapp' => ['whatsapp'],
            'whatsapp_qr' => ['whatsapp_qr'],
            'instagram' => ['instagram'],
            'messenger' => ['messenger'],
            default => [$logicalChannel],
        };

        return ChannelAccount::where('workspace_id', $this->workspaceId)
            ->whereIn('channel', $dbChannels)
            ->count();
    }

    public function canConnect(string $logicalChannel): bool
    {
        $limit = $this->limitFor($logicalChannel);

        return $limit === null || $this->usedFor($logicalChannel) < $limit;
    }

    /** Human message for a blocked connect (used by redirect flows too). */
    public function blockMessage(string $logicalChannel): string
    {
        $label = self::CHANNEL_KEYS[$logicalChannel]['label'] ?? $logicalChannel;
        $limit = $this->limitFor($logicalChannel);
        $used = $this->usedFor($logicalChannel);

        return "Your plan allows {$limit} {$label} account".($limit === 1 ? '' : 's')
            ." — you already have {$used}. Disconnect one or upgrade your plan to connect more.";
    }

    /**
     * Blocking guard: throws a 402 with an upgrade_required payload when the
     * workspace is at its limit (same contract EnforceLimit uses, so the
     * frontend upgrade flow treats every limit identically).
     */
    public function blockIfExhausted(string $logicalChannel): void
    {
        if ($this->canConnect($logicalChannel)) {
            return;
        }

        $message = $this->blockMessage($logicalChannel);
        $limit = $this->limitFor($logicalChannel);
        $used = $this->usedFor($logicalChannel);

        abort(response()->json([
            // 'message' + 'error' both — the various connect frontends read one or the other.
            'message' => $message,
            'error' => $message,
            'upgrade_required' => true,
            'limit_key' => self::CHANNEL_KEYS[$logicalChannel]['limit'] ?? $logicalChannel,
            'limit' => $limit,
            'current' => $used,
        ], 402));
    }

    /** Usage snapshot for setup UIs: null limit ⇒ no badge needed. */
    public function snapshot(string $logicalChannel): array
    {
        $limit = $this->limitFor($logicalChannel);

        return [
            'limit' => $limit,
            'used' => $this->usedFor($logicalChannel),
            'exhausted' => $limit !== null && $this->usedFor($logicalChannel) >= $limit,
        ];
    }
}

<?php

namespace App\Modules\Instagram\Services;

use Illuminate\Support\Facades\Log;

/**
 * Dedicated file logging for the Instagram module.
 *
 * Every event is written to storage/logs/instagram/ — a master instagram.log
 * plus a per-category daily file, so "everything Instagram" lives in one
 * folder and each concern can be tailed independently:
 *
 *   instagram.log    everything (master)
 *   webhook.log      raw webhook receives, signature failures, dispatches
 *   comment.log      comment received/matched/ignored/dropped
 *   dm.log           inbound DM replies, forwarded messaging events
 *   send.log         private replies + nudges (Graph send attempts/results)
 *   delivery.log     lead deliveries (link/file/text follow-ups)
 *   funnel.log       state transitions (stage changes, participant lifecycle)
 *   mirror.log       Inbox mirroring outcomes
 *   connect.log      OAuth connect/disconnect, webhook subscription registration
 *   timeout.log      7d/24h sweeps, stranded-send healing
 *
 * Every call is try/catch-guarded: logging must NEVER break the funnel.
 */
class InstagramLog
{
    public const MASTER = 'instagram';

    public const WEBHOOK = 'instagram_webhook';

    public const COMMENT = 'instagram_comment';

    public const DM = 'instagram_dm';

    public const SEND = 'instagram_send';

    public const DELIVERY = 'instagram_delivery';

    public const FUNNEL = 'instagram_funnel';

    public const MIRROR = 'instagram_mirror';

    public const CONNECT = 'instagram_connect';

    public const TIMEOUT = 'instagram_timeout';

    public const FLOW = 'instagram_flow';

    public static function webhook(string $level, string $message, array $ctx = []): void
    {
        self::write(self::WEBHOOK, $level, $message, $ctx);
    }

    public static function comment(string $level, string $message, array $ctx = []): void
    {
        self::write(self::COMMENT, $level, $message, $ctx);
    }

    public static function dm(string $level, string $message, array $ctx = []): void
    {
        self::write(self::DM, $level, $message, $ctx);
    }

    public static function send(string $level, string $message, array $ctx = []): void
    {
        self::write(self::SEND, $level, $message, $ctx);
    }

    public static function delivery(string $level, string $message, array $ctx = []): void
    {
        self::write(self::DELIVERY, $level, $message, $ctx);
    }

    public static function funnel(string $level, string $message, array $ctx = []): void
    {
        self::write(self::FUNNEL, $level, $message, $ctx);
    }

    public static function flow(string $level, string $message, array $ctx = []): void
    {
        self::write(self::FLOW, $level, $message, $ctx);
    }

    public static function mirror(string $level, string $message, array $ctx = []): void
    {
        self::write(self::MIRROR, $level, $message, $ctx);
    }

    public static function connect(string $level, string $message, array $ctx = []): void
    {
        self::write(self::CONNECT, $level, $message, $ctx);
    }

    public static function timeout(string $level, string $message, array $ctx = []): void
    {
        self::write(self::TIMEOUT, $level, $message, $ctx);
    }

    /**
     * Master-file write (every Instagram event lands here too).
     */
    public static function any(string $level, string $message, array $ctx = []): void
    {
        self::write(self::MASTER, $level, $message, $ctx);
    }

    /** Write to the master channel + the given category channel, never throwing. */
    private static function write(string $channel, string $level, string $message, array $ctx): void
    {
        try {
            Log::channel(self::MASTER)->{$level}($message, $ctx);
            Log::channel($channel)->{$level}($message, $ctx);
        } catch (\Throwable) {
            // Logging must never break the funnel.
        }
    }
}

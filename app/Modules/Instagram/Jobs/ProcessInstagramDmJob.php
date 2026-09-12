<?php

namespace App\Modules\Instagram\Jobs;

use App\Modules\Instagram\Services\CommentFunnelService;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Inbox\Services\InstagramDriver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Handles a `messaging` event arriving on the module's instagram webhook.
 *
 * Routing rules (one-directional, module → Inbox):
 *  1. If the sender is an active funnel participant awaiting the follow gate →
 *     the funnel state machine handles it.
 *  2. Otherwise → forwarded into the Inbox pipeline (class-guarded) so regular
 *     Instagram DMs keep working exactly as they did through /webhooks/meta.
 */
class ProcessInstagramDmJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function __construct(
        private readonly string $entryId,
        private readonly array $event,
    ) {}

    public function handle(CommentFunnelService $funnel): void
    {
        $handledByFunnel = $funnel->handleDmReply($this->entryId, $this->event);

        if ($handledByFunnel) {
            return;
        }

        $this->forwardToInbox();
    }

    private function forwardToInbox(): void
    {
        if (! class_exists(InstagramDriver::class)) {
            Log::info('instagram_module: Inbox driver unavailable — DM event logged only', [
                'entry_id' => $this->entryId,
            ]);

            return;
        }

        try {
            // Reuse the Inbox module's exact processing logic so messages land on
            // the same contacts/conversations it would have created itself.
            app(InstagramDriver::class)->processWebhookPayload([
                'entry' => [['id' => $this->entryId, 'messaging' => [$this->event]]],
            ]);
        } catch (\Throwable $e) {
            // The funnel must never break because the Inbox integration changed.
            Log::warning('instagram_module: forwarding DM to Inbox failed (event logged only)', [
                'entry_id' => $this->entryId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

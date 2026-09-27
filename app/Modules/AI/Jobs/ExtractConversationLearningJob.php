<?php

namespace App\Modules\AI\Jobs;

use App\Modules\AI\Services\AiMemoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Updates the rolling conversation summary + harvests durable learned facts
 * for a conversation. Runs on the 'ai' queue so a busy LLM never delays
 * customer replies (which run on their own jobs).
 */
class ExtractConversationLearningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** One retry is plenty — the next batch will re-attempt learning anyway. */
    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(public readonly int $conversationId)
    {
        $this->onQueue('ai');
    }

    public function handle(AiMemoryService $service): void
    {
        $service->extractForConversation($this->conversationId);
    }

    public function failed(\Throwable $e): void
    {
        // Learning is best-effort: log and move on, never alarm the user.
        report($e);
    }
}

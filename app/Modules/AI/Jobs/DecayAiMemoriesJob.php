<?php

namespace App\Modules\AI\Jobs;

use App\Modules\AI\Models\AiMemory;
use App\Modules\AI\Services\AiMemoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Nightly hygiene for learned memories: decay stale usefulness, hard-cap the
 * workspace at AiMemory::MAX_MEMORIES (lowest value first).
 */
class DecayAiMemoriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $workspaceId)
    {
        $this->onQueue('ai');
    }

    public function handle(AiMemoryService $service): void
    {
        $service->decayAndPrune($this->workspaceId);
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}

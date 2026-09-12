<?php

namespace App\Modules\Instagram\Jobs;

use App\Modules\Instagram\Services\CommentFunnelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessInstagramCommentJob implements ShouldQueue
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
        private readonly array $value,
    ) {}

    public function handle(CommentFunnelService $funnel): void
    {
        $funnel->handleComment($this->entryId, $this->value);
    }
}

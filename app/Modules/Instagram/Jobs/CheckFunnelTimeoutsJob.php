<?php

namespace App\Modules\Instagram\Jobs;

use App\Modules\Instagram\Services\FunnelTimeoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckFunnelTimeoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(FunnelTimeoutService $timeouts): void
    {
        $timeouts->expireStaleParticipants();
    }
}

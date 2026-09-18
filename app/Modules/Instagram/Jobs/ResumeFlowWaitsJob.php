<?php

namespace App\Modules\Instagram\Jobs;

use App\Modules\Instagram\Services\FlowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduler sweep: flow wait_reply timeouts → timeout edge / expiry, and the
 * 7-day hard expiry for abandoned flow participants.
 */
class ResumeFlowWaitsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(FlowEngine $engine): void
    {
        $engine->resumeTimedOutWaits();
    }
}

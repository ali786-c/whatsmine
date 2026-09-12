<?php

namespace App\Modules\Instagram;

use App\Modules\Instagram\Http\Controllers\InstagramWebhookController;
use App\Modules\Instagram\Jobs\CheckFunnelTimeoutsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Instagram Comment-Automation module (Private Replies funnel).
 *
 * Fully self-contained: routes (incl. its own /webhooks/instagram endpoint),
 * migrations, config namespace and scheduler are all registered here. The app's
 * ModuleServiceProvider auto-discovers this provider, and nothing else in the
 * codebase hard-depends on it — deleting this folder removes the module cleanly.
 */
class InstagramServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/instagram.php', 'instagram');
    }

    public function boot(): void
    {
        // Master switch — false makes the module fully dormant (no routes, no
        // scheduler, no webhook processing) without deleting anything.
        if (! config('instagram.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        $this->registerWebhookRoutes();
        $this->registerClientRoutes();
        $this->registerScheduler();
    }

    /**
     * Dedicated endpoint for the `instagram` webhook object. Kept separate from
     * the Inbox module's /webhooks/meta endpoint so the two integrations cannot
     * interfere with each other and either can be removed independently.
     */
    private function registerWebhookRoutes(): void
    {
        Route::middleware('throttle:webhooks')
            ->prefix('webhooks/instagram')
            ->name('webhooks.instagram.')
            ->group(function (): void {
                Route::get('/{token}', [InstagramWebhookController::class, 'verify'])->name('verify');
                Route::post('/{token}', [InstagramWebhookController::class, 'receive'])->name('receive');
            });
    }

    private function registerClientRoutes(): void
    {
        Route::middleware(['web', 'client-app'])
            ->prefix('app/instagram')
            ->name('client.instagram.')
            ->group(__DIR__.'/routes/web.php');
    }

    /** Enforce Meta's 7-day private-reply window and the 24h follow-up window. */
    private function registerScheduler(): void
    {
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)->job(new CheckFunnelTimeoutsJob)
                ->everyFifteenMinutes()
                ->withoutOverlapping();
        });
    }
}

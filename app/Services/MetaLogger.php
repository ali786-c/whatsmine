<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class MetaLogger
{
    /**
     * Log a Meta activity to storage/logs/meta.log
     */
    public static function log(string $action, array $context = [], string $level = 'info'): void
    {
        try {
            $logger = Log::build([
                'driver' => 'single',
                'path'   => storage_path('logs/meta.log'),
            ]);

            match ($level) {
                'error'    => $logger->error($action, $context),
                'warning'  => $logger->warning($action, $context),
                'debug'    => $logger->debug($action, $context),
                default    => $logger->info($action, $context),
            };
        } catch (\Throwable $e) {
            Log::warning("MetaLogger fallback [{$action}]: " . $e->getMessage(), $context);
        }
    }
}

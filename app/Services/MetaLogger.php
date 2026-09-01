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

            // Convert deeply nested arrays (like payload) to formatted JSON strings to prevent Monolog depth truncation
            $formattedContext = [];
            foreach ($context as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $formattedContext[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                } else {
                    $formattedContext[$key] = $value;
                }
            }

            match ($level) {
                'error'    => $logger->error($action, $formattedContext),
                'warning'  => $logger->warning($action, $formattedContext),
                'debug'    => $logger->debug($action, $formattedContext),
                default    => $logger->info($action, $formattedContext),
            };
        } catch (\Throwable $e) {
            Log::warning("MetaLogger fallback [{$action}]: " . $e->getMessage(), $context);
        }
    }
}

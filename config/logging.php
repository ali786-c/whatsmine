<?php

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        // Structured JSON log — one JSON object per line, includes request_id, workspace_id, user_id
        'json' => [
            'driver' => 'daily',
            'path' => storage_path('logs/structured.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => 14,
            'formatter' => JsonFormatter::class,
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        'errors' => [
            'driver' => 'daily',
            'path' => storage_path('logs/errors.log'),
            'level' => 'error',
            'days' => env('LOG_ERROR_DAYS', 14),
            'replace_placeholders' => true,
        ],

        /*
        |----------------------------------------------------------------------
        | Instagram module — dedicated per-category logs
        |----------------------------------------------------------------------
        | Everything the Instagram comment-automation module does is written
        | to storage/logs/instagram/ — one daily file per category plus a
        | master instagram.log that receives every event. Every write goes
        | to BOTH the master and the category file (see InstagramLog).
        */
        'instagram' => [
            'driver' => 'daily',
            'path' => storage_path('logs/instagram/instagram.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_INSTAGRAM_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'instagram_webhook' => [
            'driver' => 'daily',
            'path' => storage_path('logs/instagram/webhook.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_INSTAGRAM_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'instagram_comment' => [
            'driver' => 'daily',
            'path' => storage_path('logs/instagram/comment.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_INSTAGRAM_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'instagram_dm' => [
            'driver' => 'daily',
            'path' => storage_path('logs/instagram/dm.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_INSTAGRAM_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'instagram_send' => [
            'driver' => 'daily',
            'path' => storage_path('logs/instagram/send.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_INSTAGRAM_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'instagram_delivery' => [
            'driver' => 'daily',
            'path' => storage_path('logs/instagram/delivery.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_INSTAGRAM_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'instagram_funnel' => [
            'driver' => 'daily',
            'path' => storage_path('logs/instagram/funnel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_INSTAGRAM_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'instagram_mirror' => [
            'driver' => 'daily',
            'path' => storage_path('logs/instagram/mirror.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_INSTAGRAM_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'instagram_connect' => [
            'driver' => 'daily',
            'path' => storage_path('logs/instagram/connect.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_INSTAGRAM_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'instagram_timeout' => [
            'driver' => 'daily',
            'path' => storage_path('logs/instagram/timeout.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_INSTAGRAM_DAYS', 14),
            'replace_placeholders' => true,
        ],

    ],

];

<?php
/**
 * Laravel Configuration for taha-rafi/php-zkteco
 *
 * Publish with:
 *   php artisan vendor:publish --tag=zkteco-config
 *
 * @package   TahaRafi\ZKTeco
 * @author    Taha Rafi <connect@taharafi.com>
 * @license   MIT
 */
declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default ZKTeco Device Connection Settings
    |--------------------------------------------------------------------------
    */
    'ip'       => function_exists('env') ? env('ZKTECO_IP', '192.168.1.201') : '192.168.1.201',
    'port'     => function_exists('env') ? (int)env('ZKTECO_PORT', 4370) : 4370,
    'comm_key' => function_exists('env') ? (int)env('ZKTECO_COMM_KEY', 0) : 0,
    'protocol' => function_exists('env') ? env('ZKTECO_PROTOCOL', 'auto') : 'auto',
    'timeout'  => function_exists('env') ? (int)env('ZKTECO_TIMEOUT', 5) : 5,

    /*
    |--------------------------------------------------------------------------
    | Outbound Webhook Dispatcher Settings
    |--------------------------------------------------------------------------
    */
    'webhook' => [
        'url'    => function_exists('env') ? env('ZKTECO_WEBHOOK_URL') : null,
        'secret' => function_exists('env') ? env('ZKTECO_WEBHOOK_SECRET') : null,
        'events' => ['*'],
    ],

    /*
    |--------------------------------------------------------------------------
    | ADMS / iClock Cloud Push Server Settings
    |--------------------------------------------------------------------------
    */
    'adms' => [
        'queue_file'      => function_exists('storage_path')
            ? storage_path('app/zkteco_adms_queue.json')
            : sys_get_temp_dir() . '/zkteco_adms_queue.json',
        'timezone_offset' => function_exists('env') ? (int)env('ZKTECO_TIMEZONE_OFFSET', 5) : 5,
    ],
];

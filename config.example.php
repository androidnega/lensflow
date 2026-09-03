<?php
return [
    'app_name' => 'LensFlow',
    'base_url' => '', // e.g. https://book.example.com ; leave blank for auto-detection
    'timezone' => 'Africa/Accra',
    'currency' => 'GHS',
    'admin_email' => 'admin@example.com',

    // Photographer / payment details
    'photographer_name' => 'Your Studio Name',
    'momo_number' => '0240000000',
    'momo_network' => 'MTN',
    'momo_account_name' => 'Your Studio Name',

    // Database. SQLite is the default for simple deployment.
    'database' => [
        'driver' => 'sqlite',
        'path' => __DIR__ . '/storage/database.sqlite',
    ],

    // SMS: "log" works out of the box and writes to storage/logs/sms.log.
    // "webhook" POSTs JSON to a provider endpoint you control or configure.
    'sms' => [
        'driver' => 'log',
        'webhook_url' => '',
        'api_key' => '',
        'sender' => 'LensFlow',
    ],

    // Security
    'session_name' => 'lensflow_session',
    'app_key' => 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET',
];
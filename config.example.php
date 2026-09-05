<?php
return [
    'app_name' => 'iBuk.online',
    'base_url' => '', // e.g. https://book.example.com ; leave blank for auto-detection
    'timezone' => 'Africa/Accra',
    'currency' => 'GHS',
    'admin_email' => 'admin@example.com',
    'admin_login' => 'admin',
    'admin_phone' => '0200000000',
    'admin_password' => 'ChangeMeBeforeProduction',

    // Photographer / payment details
    'photographer_name' => 'iBuk.online',
    'momo_number' => '0240000000',
    'momo_network' => 'MTN',
    'momo_account_name' => 'iBuk.online',
    'whatsapp_number' => '0541069241',

    // Database. SQLite is the default for simple deployment.
    'database' => [
        'driver' => 'sqlite',
        'path' => __DIR__ . '/storage/database.sqlite',
    ],

    // SMS defaults (override live keys in Admin → Settings).
    // Drivers in settings: log | arkesel | moolre
    'sms' => [
        'driver' => 'log',
        'webhook_url' => '',
        'api_key' => '',
        'arkesel_api_key' => '',
        'moolre_vas_key' => '',
        'sender' => 'iBuk',
    ],

    // Security
    'session_name' => 'ibuk_session',
    'app_key' => 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET',
];

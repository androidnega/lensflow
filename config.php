<?php
return [
    'app_name' => 'iBuk.online',
    'base_url' => '', // e.g. https://book.example.com ; leave blank for auto-detection
    'timezone' => 'Africa/Accra',
    'currency' => 'GHS',
    'admin_email' => 'admin@ibuk.online',
    'admin_login' => 'admin',
    'admin_phone' => '0257940791',
    'admin_password' => 'admin123',

    // Photographer / payment details
    'photographer_name' => 'iBuk.online',
    'momo_number' => '0257940791',
    'momo_network' => 'MTN',
    'momo_account_name' => 'iBuk.online',
    'whatsapp_number' => '0541069241',

    // Database. SQLite is the default for simple deployment.
    'database' => [
        'driver' => 'sqlite',
        'path' => __DIR__ . '/storage/database.sqlite',
    ],

    // SMS fallback order is Moolre first, then Arkesel when both keys exist.
    // Keys can come from dashboard Settings, these config values, or env vars:
    // SMS_PROVIDER, SMS_SENDER, SMS_MOOLRE_VAS_KEY, SMS_ARKESEL_API_KEY.
    'sms' => [
        'driver' => 'moolre',
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

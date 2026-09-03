<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo '<h1>LensFlow is not configured</h1><p>Copy <code>config.example.php</code> to <code>config.php</code> and update your studio details.</p>';
    exit;
}

$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'Africa/Accra');

require __DIR__ . '/app/bootstrap.php';
$app = new App($config);
$app->run();
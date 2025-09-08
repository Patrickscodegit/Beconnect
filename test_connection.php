<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$api = app(\App\Services\Export\Clients\RobawsApiClient::class);

echo "=== Testing Improved Connection Test ===\n";

$result = $api->testConnection();
echo "Connection test result:\n";
echo json_encode($result, JSON_PRETTY_PRINT) . "\n";

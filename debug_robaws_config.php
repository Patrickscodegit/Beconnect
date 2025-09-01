<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$config = config('services.robaws');

echo "=== Robaws Configuration ===" . PHP_EOL;
echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

echo PHP_EOL . "=== Auth Check ===" . PHP_EOL;
echo "Auth method: " . ($config['auth'] ?? 'not set') . PHP_EOL;
echo "Has username: " . (empty($config['username']) ? 'NO' : 'YES') . PHP_EOL;
echo "Has password: " . (empty($config['password']) ? 'NO' : 'YES') . PHP_EOL;
echo "Has token: " . (empty($config['token']) ? 'NO' : 'YES') . PHP_EOL;
echo "Has api_key: " . (empty($config['api_key']) ? 'NO' : 'YES') . PHP_EOL;

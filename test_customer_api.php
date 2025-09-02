<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Export\Clients\RobawsApiClient;

echo "=== Testing Robaws Customer API ===" . PHP_EOL;

$apiClient = new RobawsApiClient();

// Test direct API call to see what endpoints are available
try {
    // Test different customer endpoints to see what works
    $reflection = new ReflectionClass($apiClient);
    $makeRequestMethod = $reflection->getMethod('makeRequest');
    $makeRequestMethod->setAccessible(true);
    
    echo "Testing /api/v2/customers endpoint..." . PHP_EOL;
    $response = $makeRequestMethod->invoke($apiClient, 'GET', '/api/v2/customers', []);
    echo "Status: " . $response->status() . PHP_EOL;
    echo "Response: " . $response->body() . PHP_EOL;
    
    if ($response->successful()) {
        $data = $response->json();
        echo "Data structure: " . json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL;
    }
    
} catch (Exception $e) {
    echo "Error testing API: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "=== Testing with search parameter ===" . PHP_EOL;

try {
    $reflection = new ReflectionClass($apiClient);
    $makeRequestMethod = $reflection->getMethod('makeRequest');
    $makeRequestMethod->setAccessible(true);
    
    $response = $makeRequestMethod->invoke($apiClient, 'GET', '/api/v2/customers', ['search' => 'test']);
    echo "Status: " . $response->status() . PHP_EOL;
    echo "Response: " . $response->body() . PHP_EOL;
    
} catch (Exception $e) {
    echo "Error testing search: " . $e->getMessage() . PHP_EOL;
}

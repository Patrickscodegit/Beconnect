<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Export\Clients\RobawsApiClient;

echo "=== Testing Temp Bucket Upload Method ===" . PHP_EOL;

$apiClient = new RobawsApiClient();

// Use the offer ID we found (14)
$offerId = 14;

echo "Testing temp bucket upload to offer ID: {$offerId}" . PHP_EOL;

// Create a simple test file
$testFile = 'simple_test.txt';
file_put_contents($testFile, "Test document upload\nDate: " . date('Y-m-d H:i:s'));

try {
    $result = $apiClient->attachFileToOffer($offerId, $testFile);
    
    echo "✅ Upload successful!" . PHP_EOL;
    echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    
} catch (Exception $e) {
    echo "❌ Upload failed: " . $e->getMessage() . PHP_EOL;
    
    // Let's try the temp bucket method directly to debug
    echo PHP_EOL . "Trying temp bucket method manually..." . PHP_EOL;
    
    $reflection = new ReflectionClass($apiClient);
    $makeRequestMethod = $reflection->getMethod('makeRequest');
    $makeRequestMethod->setAccessible(true);
    
    // Step 1: Create bucket
    $bucketResponse = $makeRequestMethod->invoke($apiClient, 'POST', '/api/v2/temp-document-buckets');
    
    echo "Bucket creation status: " . $bucketResponse->status() . PHP_EOL;
    if ($bucketResponse->successful()) {
        $bucket = $bucketResponse->json();
        echo "Bucket data: " . json_encode($bucket) . PHP_EOL;
    } else {
        echo "Bucket error: " . $bucketResponse->body() . PHP_EOL;
    }
    
} finally {
    if (file_exists($testFile)) {
        unlink($testFile);
    }
}

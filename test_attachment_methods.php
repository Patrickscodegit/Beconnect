<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Http;

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing different attachment methods for Robaws offer 11443...\n";

$baseUrl = config('services.robaws.base_url', 'https://app.robaws.com');
$username = config('services.robaws.username');
$password = config('services.robaws.password');

if (!$username || !$password) {
    echo "Error: Robaws credentials not configured\n";
    exit(1);
}

$offerId = '11443';
$documentId = '107102';

try {
    // Method 1: Try POST to offers/{id}/documents/attach  
    echo "\n1. Testing POST /offers/{$offerId}/documents/attach...\n";
    $response = Http::timeout(60)
        ->withBasicAuth($username, $password)
        ->acceptJson()
        ->post($baseUrl . "/api/v2/offers/{$offerId}/documents/attach", [
            'documentId' => $documentId
        ]);
        
    echo "Status: " . $response->status() . "\n";
    echo "Response: " . $response->body() . "\n";
    
    // Check if it worked
    echo "\nChecking documents after method 1...\n";
    $check = Http::timeout(60)
        ->withBasicAuth($username, $password)
        ->acceptJson()
        ->get($baseUrl . "/api/v2/offers/{$offerId}/documents");
    echo "Documents: " . json_encode($check->json(), JSON_PRETTY_PRINT) . "\n";
    
    if (!empty($check->json())) {
        echo "SUCCESS: Document attached!\n";
        exit(0);
    }
    
    // Method 2: Try POST to offers/{id}/documents (without attach)
    echo "\n2. Testing POST /offers/{$offerId}/documents...\n";
    $response = Http::timeout(60)
        ->withBasicAuth($username, $password)
        ->acceptJson()
        ->post($baseUrl . "/api/v2/offers/{$offerId}/documents", [
            'documentId' => $documentId
        ]);
        
    echo "Status: " . $response->status() . "\n";
    echo "Response: " . $response->body() . "\n";
    
    // Check if it worked
    echo "\nChecking documents after method 2...\n";
    $check = Http::timeout(60)
        ->withBasicAuth($username, $password)
        ->acceptJson()
        ->get($baseUrl . "/api/v2/offers/{$offerId}/documents");
    echo "Documents: " . json_encode($check->json(), JSON_PRETTY_PRINT) . "\n";
    
    if (!empty($check->json())) {
        echo "SUCCESS: Document attached!\n";
        exit(0);
    }
    
    // Method 3: Try PATCH with different payload structure
    echo "\n3. Testing PATCH /offers/{$offerId} with documents array...\n";
    $response = Http::timeout(60)
        ->withBasicAuth($username, $password)
        ->withHeaders(['Content-Type' => 'application/merge-patch+json'])
        ->patch($baseUrl . "/api/v2/offers/{$offerId}", [
            'documents' => [$documentId]
        ]);
        
    echo "Status: " . $response->status() . "\n";
    echo "Response: " . $response->body() . "\n";
    
    // Check if it worked
    echo "\nChecking documents after method 3...\n";
    $check = Http::timeout(60)
        ->withBasicAuth($username, $password)
        ->acceptJson()
        ->get($baseUrl . "/api/v2/offers/{$offerId}/documents");
    echo "Documents: " . json_encode($check->json(), JSON_PRETTY_PRINT) . "\n";
    
    if (!empty($check->json())) {
        echo "SUCCESS: Document attached!\n";
        exit(0);
    }
    
    echo "\nNone of the methods worked. Document is still not attached.\n";
    
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

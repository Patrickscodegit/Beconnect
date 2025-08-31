<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Http;

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing Robaws API for offer 11443 documents...\n";

$baseUrl = config('services.robaws.base_url', 'https://app.robaws.com');
$username = config('services.robaws.username');
$password = config('services.robaws.password');

if (!$username || !$password) {
    echo "Error: Robaws credentials not configured\n";
    exit(1);
}

try {
    // Test offer details first
    echo "\n1. Testing offer details...\n";
    $response = Http::timeout(60)
        ->withBasicAuth($username, $password)
        ->acceptJson()
        ->get($baseUrl . '/api/v2/offers/11443');
        
    echo "Status: " . $response->status() . "\n";
    if ($response->successful()) {
        $offer = $response->json();
        echo "Offer ID: " . ($offer['id'] ?? 'N/A') . "\n";
        echo "Customer: " . ($offer['customer'] ?? 'N/A') . "\n";
        echo "Status: " . ($offer['status'] ?? 'N/A') . "\n";
    } else {
        echo "Error: " . $response->body() . "\n";
    }
    
    // Test offer documents
    echo "\n2. Testing offer documents...\n";
    $response = Http::timeout(60)
        ->withBasicAuth($username, $password)
        ->acceptJson()
        ->get($baseUrl . '/api/v2/offers/11443/documents');
        
    echo "Status: " . $response->status() . "\n";
    if ($response->successful()) {
        $documents = $response->json();
        echo "Documents response: " . json_encode($documents, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "Error: " . $response->body() . "\n";
    }
    
    // Test document details directly
    echo "\n3. Testing document 107102 details...\n";
    $response = Http::timeout(60)
        ->withBasicAuth($username, $password)
        ->acceptJson()
        ->get($baseUrl . '/api/v2/documents/107102');
        
    echo "Status: " . $response->status() . "\n";
    if ($response->successful()) {
        $document = $response->json();
        echo "Document response: " . json_encode($document, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "Error: " . $response->body() . "\n";
    }
    
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

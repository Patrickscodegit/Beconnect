<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$baseUrl = config('services.robaws.base_url');
$username = config('services.robaws.username');
$password = config('services.robaws.password');

echo "Direct search for Carhanco with client ID approach...\n";

// Create HTTP client with basic auth
$http = Http::baseUrl($baseUrl)
    ->withBasicAuth($username, $password)
    ->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ])
    ->timeout(30);

// Based on the screenshot, let's try to find a client ID that might be Carhanco
// Let's search through more pages systematically
echo "\n--- Systematic search through all pages ---\n";

$response = $http->get('/api/v2/clients', ['size' => 1, 'page' => 0]);
if ($response->successful()) {
    $data = $response->json();
    $totalItems = $data['totalItems'] ?? 0;
    $totalPages = $data['totalPages'] ?? 0;
    
    echo "Total clients: {$totalItems}\n";
    echo "Total pages: {$totalPages}\n";
    
    // If there are too many pages, let's search by iterating through client IDs directly
    echo "\n--- Testing direct client ID access ---\n";
    
    // Test a range of client IDs to see if we can find Carhanco
    // Based on the existing client IDs we saw (4, 5, 6, 7, 8), let's test a wider range
    $testIds = [];
    
    // Test some common ID ranges
    for ($i = 1; $i <= 50; $i++) {
        $testIds[] = $i;
    }
    
    // Test some higher ranges based on typical ID patterns
    for ($i = 100; $i <= 120; $i++) {
        $testIds[] = $i;
    }
    
    for ($i = 200; $i <= 220; $i++) {
        $testIds[] = $i;
    }
    
    foreach ($testIds as $clientId) {
        $response = $http->get("/api/v2/clients/{$clientId}");
        if ($response->successful()) {
            $client = $response->json();
            $name = $client['name'] ?? '';
            
            if (stripos($name, 'carhanco') !== false) {
                echo "✓ FOUND CARHANCO: ID {$clientId}, Name: \"{$name}\"\n";
                echo "  Full client data:\n";
                echo "  Email: " . ($client['email'] ?? 'N/A') . "\n";
                echo "  Phone: " . ($client['tel'] ?? 'N/A') . "\n";
                echo "  VAT: " . ($client['vatIdNumber'] ?? 'N/A') . "\n";
                break;
            }
            
            // Also check for any clients with similar names
            if (stripos($name, 'car') !== false && strlen($name) < 20) {
                echo "  Similar: ID {$clientId}, Name: \"{$name}\"\n";
            }
        } else if ($response->status() !== 404) {
            echo "  Error accessing client {$clientId}: {$response->status()}\n";
        }
        
        // Add a small delay to avoid rate limiting
        usleep(50000); // 50ms
    }
    
} else {
    echo "Failed to get client info: {$response->status()}\n";
}

echo "\n✅ Direct ID search completed\n";

<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$baseUrl = config('services.robaws.base_url');
$username = config('services.robaws.username');
$password = config('services.robaws.password');

echo "Testing Robaws client search for 'Carhanco'...\n";
echo "Base URL: {$baseUrl}\n";
echo "Auth: basic (username: {$username})\n";

// Create HTTP client with basic auth
$http = Http::baseUrl($baseUrl)
    ->withBasicAuth($username, $password)
    ->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ])
    ->timeout(30);

// Test 1: Paginated search for Carhanco
echo "\n--- Test 1: Paginated search for Carhanco ---\n";
$found = false;
$maxPages = 20; // Search first 20 pages (1000 clients)

for ($page = 0; $page < $maxPages && !$found; $page++) {
    echo "Searching page {$page}...\n";
    $response = $http->get('/api/v2/clients', ['size' => 50, 'page' => $page]);
    
    if ($response->successful()) {
        $data = $response->json();
        $items = $data['items'] ?? [];
        
        foreach ($items as $client) {
            $name = strtolower($client['name'] ?? '');
            if (strpos($name, 'carhanco') !== false) {
                $found = true;
                echo "  ✓ FOUND - ID: {$client['id']}, Name: \"{$client['name']}\"\n";
                
                // Test if this client would be found by our search methods
                echo "  Testing search methods for this client:\n";
                
                $testSearches = [$client['name'], 'Carhanco', 'Carhanco bv'];
                foreach ($testSearches as $searchTerm) {
                    $testResponse = $http->get('/api/v2/clients', ['search' => $searchTerm, 'size' => 10]);
                    if ($testResponse->successful()) {
                        $testData = $testResponse->json();
                        $testItems = $testData['items'] ?? [];
                        $foundInSearch = false;
                        foreach ($testItems as $testClient) {
                            if ($testClient['id'] == $client['id']) {
                                $foundInSearch = true;
                                break;
                            }
                        }
                        echo "    Search '{$searchTerm}': " . ($foundInSearch ? "✓ Found" : "✗ Not found") . "\n";
                    }
                }
                break;
            }
        }
        
        if (empty($items)) {
            echo "  No more results on page {$page}\n";
            break;
        }
    } else {
        echo "  Failed to search page {$page}: {$response->status()}\n";
        break;
    }
    
    // Add small delay to avoid rate limiting
    usleep(100000); // 100ms
}

if (!$found) {
    echo "  ❌ Carhanco not found in first {$maxPages} pages\n";
    
    // Try searching for 'car' to see if we can find related entries
    echo "\n--- Fallback: Search for 'car' ---\n";
    $response = $http->get('/api/v2/clients', ['search' => 'car', 'size' => 20]);
    if ($response->successful()) {
        $data = $response->json();
        $items = $data['items'] ?? [];
        echo "Found " . count($items) . " clients containing 'car':\n";
        foreach ($items as $client) {
            echo "  - ID: {$client['id']}, Name: \"{$client['name']}\"\n";
        }
    }
}

echo "\n✅ Search completed\n";

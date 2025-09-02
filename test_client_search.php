<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$baseUrl = config('services.robaws.base_url');
$username = config('services.robaws.username');
$password = config('services.robaws.password');

echo "Testing Robaws client search for 'Carhanco'..." . PHP_EOL;
echo "Base URL: {$baseUrl}" . PHP_EOL;
echo "Auth: basic (username: {$username})" . PHP_EOL;

// Create HTTP client with basic auth
$http = Http::baseUrl($baseUrl)
    ->withBasicAuth($username, $password)
    ->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ])
    ->timeout(30);

// Test 1: Search with 'search' parameter
echo "\n--- Test 1: search=Carhanco ---" . PHP_EOL;
$response = $http->get('/api/v2/clients', ['search' => 'Carhanco', 'size' => 20]);
if ($response->successful()) {
    $data = $response->json();
    $items = $data['items'] ?? [];
    echo "Found " . count($items) . " clients:" . PHP_EOL;
    foreach ($items as $client) {
        echo "  - ID: {$client['id']}, Name: \"{$client['name']}\"" . PHP_EOL;
    }
} else {
    echo "Search failed: {$response->status()} - {$response->body()}" . PHP_EOL;
}

// Test 2: Search with 'name' parameter
echo "\n--- Test 2: name=Carhanco bv ---" . PHP_EOL;
$response = $http->get('/api/v2/clients', ['name' => 'Carhanco bv', 'size' => 20]);
if ($response->successful()) {
    $data = $response->json();
    $items = $data['items'] ?? [];
    echo "Found " . count($items) . " clients:" . PHP_EOL;
    foreach ($items as $client) {
        echo "  - ID: {$client['id']}, Name: \"{$client['name']}\"" . PHP_EOL;
    }
} else {
    echo "Search failed: {$response->status()} - {$response->body()}" . PHP_EOL;
}

// Test 3: Get more clients to see if Carhanco is there
echo "\n--- Test 3: Get larger sample ---" . PHP_EOL;
$response = $http->get('/api/v2/clients', ['size' => 50, 'page' => 0]);
if ($response->successful()) {
    $data = $response->json();
    $items = $data['items'] ?? [];
    echo "Checking " . count($items) . " clients for Carhanco..." . PHP_EOL;
    
    $found = [];
    foreach ($items as $client) {
        $name = strtolower($client['name'] ?? '');
        if (strpos($name, 'carhanco') !== false) {
            $found[] = $client;
            echo "  ✓ FOUND - ID: {$client['id']}, Name: \"{$client['name']}\"" . PHP_EOL;
        }
    }
    
    if (empty($found)) {
        echo "  No clients containing 'carhanco' found in first 50 clients" . PHP_EOL;
        echo "  Sample client names:" . PHP_EOL;
        foreach (array_slice($items, 0, 5) as $client) {
            echo "    - \"{$client['name']}\"" . PHP_EOL;
        }
    }
    
// Test 4: Search with pagination to find Carhanco
echo "\n--- Test 4: Paginated search for Carhanco ---" . PHP_EOL;
$found = false;
$maxPages = 10; // Search first 10 pages (500 clients)

for ($page = 0; $page < $maxPages && !$found; $page++) {
    echo "Searching page {$page}..." . PHP_EOL;
    $response = $http->get('/api/v2/clients', ['search' => 'Carhanco', 'size' => 50, 'page' => $page]);
    
    if ($response->successful()) {
        $data = $response->json();
        $items = $data['items'] ?? [];
        
        foreach ($items as $client) {
            $name = strtolower($client['name'] ?? '');
            if (strpos($name, 'carhanco') !== false) {
                $found = true;
                echo "  ✓ FOUND - ID: {$client['id']}, Name: \"{$client['name']}\"" . PHP_EOL;
                break;
            }
        }
        
        if (empty($items)) {
            echo "  No more results on page {$page}" . PHP_EOL;
            break;
        }
    } else {
        echo "  Failed to search page {$page}: {$response->status()}" . PHP_EOL;
        break;
    }
}

if (!$found) {
    echo "  ❌ Carhanco not found in first {$maxPages} pages" . PHP_EOL;
}

// Test 5: Try exact search with different variations
echo "\n--- Test 5: Direct search variations ---" . PHP_EOL;
$variations = [
    'Carhanco',
    'Carhanco bv', 
    'CARHANCO',
    'CARHANCO BV',
    'Carhanco BV',
    'carhanco',
    'carhanco bv'
];

foreach ($variations as $search) {
    $response = $http->get('/api/v2/clients', ['search' => $search, 'size' => 10]);
    if ($response->successful()) {
        $data = $response->json();
        $items = $data['items'] ?? [];
        
        foreach ($items as $client) {
            $name = strtolower($client['name'] ?? '');
            if (strpos($name, 'carhanco') !== false) {
                echo "  ✓ Found with '{$search}': ID {$client['id']}, Name \"{$client['name']}\"" . PHP_EOL;
            }
        }
    }
}

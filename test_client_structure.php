<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$baseUrl = config('services.robaws.base_url');
$username = config('services.robaws.username');
$password = config('services.robaws.password');

echo "Analyzing Robaws API client structure...\n";

// Create HTTP client with basic auth
$http = Http::baseUrl($baseUrl)
    ->withBasicAuth($username, $password)
    ->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ])
    ->timeout(30);

// Get a few clients to analyze the structure
echo "\n--- Analyzing client data structure ---\n";
$response = $http->get('/api/v2/clients', ['size' => 5, 'page' => 0]);

if ($response->successful()) {
    $data = $response->json();
    $items = $data['items'] ?? [];
    
    foreach ($items as $index => $client) {
        echo "\n=== Client " . ($index + 1) . " ===\n";
        echo "ID: " . ($client['id'] ?? 'N/A') . "\n";
        echo "Name: " . ($client['name'] ?? 'N/A') . "\n";
        
        // Check all available fields
        echo "Available fields: " . implode(', ', array_keys($client)) . "\n";
        
        // Check if there are any ID-related fields
        foreach ($client as $key => $value) {
            if (strpos(strtolower($key), 'id') !== false || strpos(strtolower($key), 'client') !== false) {
                echo "  {$key}: {$value}\n";
            }
        }
        
        // Check if name contains any company identifiers
        $name = $client['name'] ?? '';
        if (stripos($name, 'bv') !== false || stripos($name, 'ltd') !== false || stripos($name, 'inc') !== false) {
            echo "  Company indicators found in name\n";
        }
        
        if ($index === 0) {
            echo "\nFull client structure:\n";
            echo json_encode($client, JSON_PRETTY_PRINT) . "\n";
        }
    }
    
    echo "\n--- Testing search with company suffixes ---\n";
    
    // Test searches that include company suffixes
    $testTerms = ['bv', 'BV', 'ltd', 'LTD', 'company', 'trucks'];
    foreach ($testTerms as $term) {
        $response = $http->get('/api/v2/clients', ['search' => $term, 'size' => 3]);
        if ($response->successful()) {
            $data = $response->json();
            $items = $data['items'] ?? [];
            echo "\nSearch '{$term}' found " . count($items) . " results:\n";
            foreach ($items as $client) {
                echo "  - ID: {$client['id']}, Name: \"{$client['name']}\"\n";
            }
        }
    }
    
} else {
    echo "Failed to get clients: {$response->status()} - {$response->body()}\n";
}

echo "\n✅ Analysis completed\n";

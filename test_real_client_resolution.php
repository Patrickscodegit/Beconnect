<?php

/**
 * Test Client Resolution with Existing Clients
 */

use App\Services\Robaws\ClientResolver;
use App\Services\Export\Clients\RobawsApiClient;

echo "🔍 Testing Client Resolution with Real API Data\n";
echo "==============================================\n\n";

$apiClient = app(RobawsApiClient::class);
$clientResolver = app(ClientResolver::class);

// Get first few clients from API to test with
echo "1. Fetching existing clients from API:\n";
echo "────────────────────────────────────\n";

try {
    $clientData = $apiClient->listClients(0, 10);
    $clients = $clientData['items'] ?? [];
    
    echo "Found " . count($clients) . " clients to test with:\n\n";
    
    // Show first 3 clients
    foreach (array_slice($clients, 0, 3) as $i => $client) {
        echo "Client " . ($i+1) . ":\n";
        echo "  ID: {$client['id']}\n";
        echo "  Name: {$client['name']}\n";
        echo "  Email: " . ($client['email'] ?: 'N/A') . "\n";
        echo "  Phone: " . ($client['tel'] ?: 'N/A') . "\n\n";
    }
    
    // Now test resolution with these real clients
    echo "2. Testing Resolution with Real Clients:\n";
    echo "──────────────────────────────────────\n";
    
    foreach (array_slice($clients, 0, 2) as $i => $client) {
        echo "\nTest " . ($i+1) . " - Resolving: {$client['name']}\n";
        echo str_repeat('-', 40) . "\n";
        
        // Test by name
        if (!empty($client['name'])) {
            $hints = ['name' => $client['name']];
            $result = $clientResolver->resolve($hints);
            
            if ($result) {
                echo "✅ Name Resolution: SUCCESS\n";
                echo "   Found ID: {$result['id']} (expected: {$client['id']})\n";
                echo "   Confidence: " . number_format($result['confidence'] * 100, 1) . "%\n";
                echo "   Match: " . ($result['id'] == $client['id'] ? '✅ CORRECT' : '❌ WRONG') . "\n";
            } else {
                echo "❌ Name Resolution: FAILED\n";
            }
        }
        
        // Test by email if available
        if (!empty($client['email'])) {
            echo "\n   Testing email resolution...\n";
            $hints = ['email' => $client['email']];
            $result = $clientResolver->resolve($hints);
            
            if ($result) {
                echo "   ✅ Email Resolution: SUCCESS\n";
                echo "   Found ID: {$result['id']} (expected: {$client['id']})\n";
                echo "   Match: " . ($result['id'] == $client['id'] ? '✅ CORRECT' : '❌ WRONG') . "\n";
            } else {
                echo "   ❌ Email Resolution: FAILED\n";
            }
        }
        
        // Test by phone if available  
        if (!empty($client['tel'])) {
            echo "\n   Testing phone resolution...\n";
            $hints = ['phone' => $client['tel']];
            $result = $clientResolver->resolve($hints);
            
            if ($result) {
                echo "   ✅ Phone Resolution: SUCCESS\n";
                echo "   Found ID: {$result['id']} (expected: {$client['id']})\n";
                echo "   Match: " . ($result['id'] == $client['id'] ? '✅ CORRECT' : '❌ WRONG') . "\n";
            } else {
                echo "   ❌ Phone Resolution: FAILED\n";
            }
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error fetching clients: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "🎯 This should help identify if resolution is working with real data!\n";

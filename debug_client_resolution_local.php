<?php

/**
 * Debug Client Resolution in Local Environment
 */

use App\Services\Robaws\ClientResolver;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Support\Facades\Log;

echo "🔍 Debugging Client Resolution in Local Environment\n";
echo "=================================================\n\n";

// Test 1: Check API configuration
echo "1. API Configuration Check:\n";
echo "──────────────────────────\n";

$apiClient = app(RobawsApiClient::class);
$configCheck = $apiClient->validateConfig();

echo "Config Valid: " . ($configCheck['valid'] ? 'YES' : 'NO') . "\n";
if (!empty($configCheck['issues'])) {
    echo "Issues: " . implode(', ', $configCheck['issues']) . "\n";
}

// Show current config (without sensitive data)
echo "Base URL: " . config('services.robaws.base_url') . "\n";
echo "Auth Method: " . config('services.robaws.auth', 'bearer') . "\n";
echo "API Key Set: " . (config('services.robaws.api_key') ? 'YES' : 'NO') . "\n\n";

// Test 2: Test API connection
echo "2. API Connection Test:\n";
echo "──────────────────────\n";

try {
    $connectionTest = $apiClient->testConnection();
    echo "Connection Success: " . ($connectionTest['success'] ? 'YES' : 'NO') . "\n";
    echo "Status Code: " . ($connectionTest['status'] ?? 'N/A') . "\n";
    if (isset($connectionTest['error'])) {
        echo "Error: " . $connectionTest['error'] . "\n";
    }
} catch (Exception $e) {
    echo "Connection Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: Try client resolution
echo "3. Client Resolution Test:\n";
echo "─────────────────────────\n";

$clientResolver = app(ClientResolver::class);

$testCases = [
    [
        'name' => 'Badr Algothami (Known Production Client)',
        'hints' => [
            'customer_name' => 'Badr Algothami',
            'contact_email' => 'balgothami@badrtrading.com',
        ]
    ],
    [
        'name' => 'Test Customer (Generic)',
        'hints' => [
            'customer_name' => 'Test Customer',
            'contact_email' => 'test@example.com',
        ]
    ]
];

foreach ($testCases as $case) {
    echo "\nTesting: {$case['name']}\n";
    echo str_repeat('-', 20) . "\n";
    
    try {
        $result = $clientResolver->resolve($case['hints']);
        
        if ($result) {
            echo "✅ Client Found!\n";
            echo "Client ID: {$result['id']}\n";
            echo "Client Name: {$result['name']}\n";
        } else {
            echo "❌ No client found\n";
        }
    } catch (Exception $e) {
        echo "❌ Resolution Error: " . $e->getMessage() . "\n";
    }
}

// Test 4: Check if it's a local vs production issue
echo "\n4. Environment Analysis:\n";
echo "───────────────────────\n";

echo "Environment: " . app()->environment() . "\n";
echo "Database Connection: " . config('database.default') . "\n";

// Try a direct API call to see what happens
echo "\n5. Direct API Test:\n";
echo "──────────────────\n";

try {
    // Test listing clients to see if API is working at all
    $clients = $apiClient->listClients(0, 5);
    
    if (isset($clients['content']) && is_array($clients['content'])) {
        echo "✅ API is responding\n";
        echo "Total clients available: " . ($clients['totalElements'] ?? 'unknown') . "\n";
        echo "First page clients: " . count($clients['content']) . "\n";
        
        // Show first few clients for debugging
        foreach (array_slice($clients['content'], 0, 3) as $i => $client) {
            echo "Client " . ($i+1) . ": {$client['name']} (ID: {$client['id']})\n";
        }
    } else {
        echo "❌ API not responding or unexpected format\n";
        echo "Response: " . json_encode($clients, JSON_PRETTY_PRINT) . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Direct API Error: " . $e->getMessage() . "\n";
    
    // Check if it's a config issue
    if (str_contains($e->getMessage(), 'not configured')) {
        echo "\n💡 This looks like a configuration issue.\n";
        echo "Please check your .env file has:\n";
        echo "- ROBAWS_BASE_URL\n";
        echo "- ROBAWS_API_KEY (or ROBAWS_USERNAME/ROBAWS_PASSWORD)\n";
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "🎯 Summary:\n";
echo "If no clients are found but API is working, this could indicate:\n";
echo "1. Local environment uses different API endpoint than production\n";
echo "2. API credentials point to different tenant/database\n";
echo "3. Test data doesn't exist in local API environment\n";
echo "4. Network/firewall issues blocking API access\n";

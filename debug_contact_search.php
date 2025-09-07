<?php

/**
 * Debug Contact Search Endpoints
 */

use App\Services\Export\Clients\RobawsApiClient;

echo "🔍 Debugging Contact Search Endpoints\n";
echo "====================================\n\n";

$apiClient = app(RobawsApiClient::class);

// Test direct contact search to see what the API actually returns
echo "1. Testing direct contact search:\n";
echo "────────────────────────────────\n";

$testEmail = "info@222motors.ae"; // We know this exists from previous test
$testPhone = "+971559659999";     // We know this exists too

echo "Testing email search for: {$testEmail}\n";

try {
    // Test the findContactByEmail method directly
    $result = $apiClient->findContactByEmail($testEmail);
    
    if ($result) {
        echo "✅ findContactByEmail worked!\n";
        echo "Found client ID: " . ($result['id'] ?? 'N/A') . "\n";
        echo "Client name: " . ($result['name'] ?? 'N/A') . "\n";
    } else {
        echo "❌ findContactByEmail returned null\n";
        
        // Let's try to debug why - test if we can list contacts at all
        echo "Trying to list contacts directly...\n";
        try {
            // Use reflection to access the private method for debugging
            $reflection = new ReflectionClass($apiClient);
            $method = $reflection->getMethod('getHttpClient');
            $method->setAccessible(true);
            $httpClient = $method->invoke($apiClient);
            
            $response = $httpClient->get('/api/v2/contacts', ['size' => 3]);
            
            if ($response->successful()) {
                $data = $response->json();
                echo "✅ Contacts endpoint accessible\n";
                echo "Response keys: " . implode(', ', array_keys($data)) . "\n";
                
                $items = $data['content'] ?? $data['items'] ?? [];
                echo "Found " . count($items) . " contacts\n";
                
                if (!empty($items)) {
                    $first = $items[0];
                    echo "Sample contact keys: " . implode(', ', array_keys($first)) . "\n";
                    echo "Sample email: " . ($first['email'] ?? 'N/A') . "\n";
                }
            } else {
                echo "❌ Contacts endpoint failed: " . $response->status() . "\n";
                echo "Error body: " . $response->body() . "\n";
            }
            
        } catch (Exception $e2) {
            echo "❌ Debug access failed: " . $e2->getMessage() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Email search error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("-", 40) . "\n";
echo "Testing phone search for: {$testPhone}\n";

try {
    // Test the findClientByPhone method directly
    $result = $apiClient->findClientByPhone($testPhone);
    
    if ($result) {
        echo "✅ findClientByPhone worked!\n";
        echo "Found client ID: " . ($result['id'] ?? 'N/A') . "\n";
        echo "Client name: " . ($result['name'] ?? 'N/A') . "\n";
    } else {
        echo "❌ findClientByPhone returned null\n";
    }
    
} catch (Exception $e) {
    echo "❌ Phone search error: " . $e->getMessage() . "\n";
}

// Test if the contacts endpoint works at all
echo "\n2. Testing contacts endpoint general access:\n";
echo "──────────────────────────────────────────\n";

try {
    // Use a simple direct test
    $testResult = $apiClient->findContactByEmail('nonexistent@test.com');
    echo "✅ Contact search method is callable (returned: " . ($testResult ? 'result' : 'null') . ")\n";
    
} catch (Exception $e) {
    echo "❌ Contact search method failed: " . $e->getMessage() . "\n";
    
    // This might indicate the contacts endpoint doesn't exist
    if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'Not Found')) {
        echo "💡 The /api/v2/contacts endpoint likely doesn't exist in this Robaws installation\n";
        echo "💡 This explains why email/phone resolution fails\n";
        echo "💡 Name resolution works because it uses /api/v2/clients endpoint\n";
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "🎯 This will help identify the contact search issue!\n";

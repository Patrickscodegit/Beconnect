<?php

/**
 * Test Direct Client Search by Email/Phone
 */

use App\Services\Export\Clients\RobawsApiClient;

echo "🔍 Testing Direct Client Search (bypassing contacts)\n";
echo "==================================================\n\n";

$apiClient = app(RobawsApiClient::class);

// Get a few clients to test with their actual email/phone data
try {
    $clientData = $apiClient->listClients(0, 10);
    $clients = $clientData['items'] ?? [];
    
    echo "1. Found clients with contact info:\n";
    echo "──────────────────────────────────\n";
    
    $testClients = [];
    foreach ($clients as $client) {
        if (!empty($client['email']) || !empty($client['tel'])) {
            $testClients[] = $client;
            echo "Client: {$client['name']}\n";
            echo "  Email: " . ($client['email'] ?: 'N/A') . "\n";
            echo "  Phone: " . ($client['tel'] ?: 'N/A') . "\n\n";
            
            if (count($testClients) >= 3) break; // Test with first 3 that have contact info
        }
    }
    
    if (empty($testClients)) {
        echo "❌ No clients found with email/phone data to test\n";
        exit;
    }
    
    echo "2. Testing direct client search by email/phone:\n";
    echo "─────────────────────────────────────────────\n";
    
    // Method 1: Search clients directly by filtering
    foreach ($testClients as $i => $client) {
        echo "\nTest " . ($i+1) . " - Client: {$client['name']}\n";
        echo str_repeat('-', 40) . "\n";
        
        // Test email search through clients endpoint
        if (!empty($client['email'])) {
            echo "Testing email search for: {$client['email']}\n";
            
            // Try searching through paginated client results
            $found = false;
            $page = 0;
            $maxPages = 10; // reasonable limit
            
            do {
                $pageData = $apiClient->listClients($page, 100);
                $pageClients = $pageData['items'] ?? [];
                
                foreach ($pageClients as $pc) {
                    if (!empty($pc['email']) && strcasecmp($pc['email'], $client['email']) === 0) {
                        echo "✅ Found by email! Client ID: {$pc['id']}\n";
                        $found = true;
                        break 2;
                    }
                }
                
                $page++;
                $totalItems = (int)($pageData['totalItems'] ?? 0);
            } while (!$found && $page < $maxPages && $page * 100 < $totalItems);
            
            if (!$found) {
                echo "❌ Email search through clients failed\n";
            }
        }
        
        // Test phone search through clients endpoint  
        if (!empty($client['tel'])) {
            echo "Testing phone search for: {$client['tel']}\n";
            
            $found = false;
            $page = 0;
            $maxPages = 5; // smaller limit for phone search
            
            do {
                $pageData = $apiClient->listClients($page, 50);
                $pageClients = $pageData['items'] ?? [];
                
                foreach ($pageClients as $pc) {
                    if (!empty($pc['tel'])) {
                        // Normalize phone numbers for comparison
                        $clientPhone = preg_replace('/\D+/', '', $client['tel']);
                        $testPhone = preg_replace('/\D+/', '', $pc['tel']);
                        
                        if ($clientPhone === $testPhone) {
                            echo "✅ Found by phone! Client ID: {$pc['id']}\n";
                            $found = true;
                            break 2;
                        }
                    }
                }
                
                $page++;
                $totalItems = (int)($pageData['totalItems'] ?? 0);
            } while (!$found && $page < $maxPages && $page * 50 < $totalItems);
            
            if (!$found) {
                echo "❌ Phone search through clients failed\n";
            }
        }
        
        if ($i >= 1) break; // Limit to first 2 clients to keep output manageable
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "🎯 Analysis:\n";
echo "If direct client search works, we should update the API client\n";
echo "to search through /api/v2/clients instead of /api/v2/contacts\n";
echo "for email and phone lookups.\n";

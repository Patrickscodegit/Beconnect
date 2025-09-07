<?php

/**
 * Deep Debug of Offer Client Relationship
 */

use App\Services\Export\Clients\RobawsApiClient;

echo "🔍 Deep Debugging Client Relationship in Created Offer\n";
echo "=====================================================\n\n";

$apiClient = app(RobawsApiClient::class);

// Let's get a fresh offer that we know has a clientId
echo "1. Creating a new offer with client ID...\n";
echo "────────────────────────────────────────\n";

$testIntake = \App\Models\Intake::factory()->create([
    'customer_name' => '222 CARS',
    'contact_email' => 'info@222motors.ae', 
    'robaws_client_id' => 3473
]);

$exportService = app(\App\Services\Robaws\RobawsExportService::class);
$result = $exportService->exportIntake($testIntake, [
    'customerName' => '222 CARS',
    'contactEmail' => 'info@222motors.ae',
]);

if ($result['success']) {
    $offerId = $result['quotation_id'];
    echo "✅ Created offer ID: {$offerId}\n\n";
    
    // Now let's examine the offer in detail
    echo "2. Examining Offer Details:\n";
    echo "─────────────────────────\n";
    
    $offerResponse = $apiClient->getOffer($offerId);
    
    if ($offerResponse['success']) {
        $offer = $offerResponse['data'];
        
        echo "Offer ID: " . ($offer['id'] ?? 'N/A') . "\n";
        echo "Title: " . ($offer['title'] ?? 'N/A') . "\n";
        echo "Client Reference: " . ($offer['clientReference'] ?? 'N/A') . "\n";
        echo "Raw clientId: " . json_encode($offer['clientId'] ?? null) . "\n";
        echo "Raw client object: " . json_encode($offer['client'] ?? null) . "\n";
        echo "Raw clientContact: " . json_encode($offer['clientContact'] ?? null) . "\n\n";
        
        // Check if clientId is set but client object is missing
        if (isset($offer['clientId']) && $offer['clientId'] && empty($offer['client'])) {
            echo "🔍 ISSUE IDENTIFIED: clientId is set but client object is null\n";
            echo "This suggests the clientId value might be invalid or the API doesn't auto-populate the client object\n\n";
            
            // Let's verify the client exists by fetching it directly
            echo "3. Verifying Client Exists:\n";
            echo "──────────────────────────\n";
            
            $clientCheck = $apiClient->getClientById($offer['clientId']);
            
            if ($clientCheck) {
                echo "✅ Client {$offer['clientId']} EXISTS in API\n";
                echo "Client Name: " . ($clientCheck['name'] ?? 'N/A') . "\n";
                echo "Client Email: " . ($clientCheck['email'] ?? 'N/A') . "\n\n";
                
                echo "4. Possible Issues:\n";
                echo "─────────────────\n";
                echo "a) Robaws API might require 'customerId' instead of 'clientId'\n";
                echo "b) The client relationship isn't auto-populated in offer responses\n";
                echo "c) We need to use a different field or API call to link clients\n";
                echo "d) The clientId might need to be an integer instead of string\n\n";
                
                // Check the exact data types
                echo "5. Data Type Analysis:\n";
                echo "────────────────────\n";
                echo "clientId type: " . gettype($offer['clientId']) . "\n";
                echo "clientId value: " . var_export($offer['clientId'], true) . "\n";
                echo "Expected client ID: 3473 (integer)\n";
                echo "Types match: " . (((int)$offer['clientId']) === 3473 ? 'YES' : 'NO') . "\n";
                
            } else {
                echo "❌ Client {$offer['clientId']} does NOT exist in API\n";
                echo "This explains why the client isn't linked!\n";
            }
        } else {
            echo "Different issue - let's analyze...\n";
            if (empty($offer['clientId'])) {
                echo "❌ clientId is not set in offer\n";
            }
            if (!empty($offer['client'])) {
                echo "✅ client object is populated\n";
            }
        }
        
    } else {
        echo "❌ Could not fetch offer details\n";
        echo "Error: " . ($offerResponse['error'] ?? 'Unknown') . "\n";
    }
} else {
    echo "❌ Failed to create test offer\n";
    echo "Error: {$result['error']}\n";
}

// Cleanup
$testIntake->delete();

echo "\n" . str_repeat("=", 60) . "\n";
echo "🎯 This should identify exactly why client linking isn't working\n";

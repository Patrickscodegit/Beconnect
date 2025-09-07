<?php

/**
 * Debug Actual Robaws Payload to See Client Linking
 */

use App\Services\Robaws\RobawsExportService;
use App\Services\Export\Mappers\RobawsMapper;
use App\Models\Intake;

echo "🔍 Debugging Actual Robaws Payload for Client Linking\n";
echo "====================================================\n\n";

// Create a test intake with the known working client
$intake = Intake::factory()->create([
    'customer_name' => '222 CARS',
    'contact_email' => 'info@222motors.ae',
    'contact_phone' => '+971559659999',
    'robaws_client_id' => 3473, // Pre-resolved client ID
]);

echo "1. Test Intake Created:\n";
echo "─────────────────────\n";
echo "Intake ID: {$intake->id}\n";
echo "Customer: {$intake->customer_name}\n";
echo "Email: {$intake->contact_email}\n";
echo "Pre-resolved Client ID: {$intake->robaws_client_id}\n";
echo "Client ID Type: " . gettype($intake->robaws_client_id) . "\n\n";

$extractionData = [
    'customerName' => '222 CARS',
    'contactEmail' => 'info@222motors.ae',
    'customerPhone' => '+971559659999',
    'vehicleDetails' => [
        'brand' => 'BMW',
        'model' => 'Serie 7',
        'year' => 2024,
    ],
];

echo "2. Building Payload Step-by-Step:\n";
echo "────────────────────────────────\n";

// Get the services
$mapper = app(RobawsMapper::class);
$exportService = app(RobawsExportService::class);

try {
    // Step 1: Map to Robaws format
    echo "Step 1: Mapping intake to Robaws format...\n";
    $mapped = $mapper->mapIntakeToRobaws($intake, $extractionData);
    
    echo "✅ Mapped data keys: " . implode(', ', array_keys($mapped)) . "\n";
    echo "Has customer_name: " . (isset($mapped['customer_name']) ? 'YES' : 'NO') . "\n";
    echo "Has contact_email: " . (isset($mapped['contact_email']) ? 'YES' : 'NO') . "\n";
    echo "Initial client_id in mapped: " . ($mapped['client_id'] ?? 'NOT SET') . "\n\n";
    
    // Step 2: Build type-safe payload (this should inject client_id)
    echo "Step 2: Building type-safe payload...\n";
    
    // We need to access the private method, so let's use reflection
    $reflection = new ReflectionClass($exportService);
    $buildPayloadMethod = $reflection->getMethod('buildTypeSeafePayload');
    $buildPayloadMethod->setAccessible(true);
    
    $exportId = 'debug-' . time();
    $finalPayload = $buildPayloadMethod->invoke($exportService, $intake, $extractionData, $mapped, $exportId);
    
    echo "✅ Type-safe payload built\n";
    echo "Final payload keys: " . implode(', ', array_keys($finalPayload)) . "\n";
    echo "Final clientId: " . ($finalPayload['clientId'] ?? 'NOT SET') . "\n";
    echo "Final contactEmail: " . ($finalPayload['contactEmail'] ?? 'NOT SET') . "\n";
    echo "Final clientReference: " . ($finalPayload['clientReference'] ?? 'NOT SET') . "\n\n";
    
    // Step 3: Show the complete payload structure
    echo "Step 3: Complete Payload Structure:\n";
    echo "──────────────────────────────────\n";
    
    // Show key client-related fields
    $clientFields = ['clientId', 'contactEmail', 'clientReference', 'customer', 'contact'];
    foreach ($clientFields as $field) {
        if (isset($finalPayload[$field])) {
            $value = $finalPayload[$field];
            if (is_array($value)) {
                echo "{$field}: " . json_encode($value) . "\n";
            } else {
                echo "{$field}: {$value}\n";
            }
        } else {
            echo "{$field}: NOT SET\n";
        }
    }
    
    echo "\nPayload size: " . strlen(json_encode($finalPayload)) . " bytes\n";
    
    // Step 4: Check what happens when we actually send it
    echo "\n4. Testing Actual API Call:\n";
    echo "─────────────────────────\n";
    
    $result = $exportService->exportIntake($intake, $extractionData);
    
    if ($result['success']) {
        echo "✅ Export succeeded\n";
        echo "Created Offer ID: {$result['quotation_id']}\n";
        
        // Now let's check if we can retrieve the offer to see if client is linked
        $apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
        $offerCheck = $apiClient->getOffer($result['quotation_id']);
        
        if ($offerCheck['success']) {
            $offerData = $offerCheck['data'];
            echo "\n5. Checking Created Offer:\n";
            echo "─────────────────────────\n";
            echo "Offer ID: " . ($offerData['id'] ?? 'N/A') . "\n";
            echo "Offer Number: " . ($offerData['number'] ?? 'N/A') . "\n";
            
            // Check if client is linked
            if (isset($offerData['client'])) {
                echo "✅ CLIENT IS LINKED!\n";
                echo "Linked Client ID: " . ($offerData['client']['id'] ?? 'N/A') . "\n";
                echo "Linked Client Name: " . ($offerData['client']['name'] ?? 'N/A') . "\n";
            } else {
                echo "❌ NO CLIENT LINKED to offer\n";
                echo "Available offer keys: " . implode(', ', array_keys($offerData)) . "\n";
                
                // Check for other client-related fields
                $possibleClientFields = ['customerId', 'clientId', 'customer', 'clientReference'];
                foreach ($possibleClientFields as $field) {
                    if (isset($offerData[$field])) {
                        echo "Found {$field}: " . json_encode($offerData[$field]) . "\n";
                    }
                }
            }
        } else {
            echo "❌ Could not retrieve created offer for verification\n";
            echo "Error: " . ($offerCheck['error'] ?? 'Unknown') . "\n";
        }
    } else {
        echo "❌ Export failed: {$result['error']}\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error during debug: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Cleanup
echo "\n🧹 Cleaning up...\n";
$intake->delete();

echo "\n" . str_repeat("=", 60) . "\n";
echo "🎯 This will show exactly what's being sent to Robaws\n";
echo "and whether the client linking is working as expected.\n";

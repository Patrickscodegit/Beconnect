<?php

/**
 * End-to-End Export Test with Real Client Data
 */

use App\Services\Robaws\ClientResolver;
use App\Services\Robaws\RobawsExportService;
use App\Models\Intake;

echo "🚀 End-to-End Export Test with Client Resolution\n";
echo "===============================================\n\n";

// Test with a real client from the API
$testClient = [
    'name' => '222 CARS',
    'email' => 'info@222motors.ae',
    'phone' => '+971559659999',
    'expected_id' => '3473'
];

echo "1. Testing Client Resolution:\n";
echo "───────────────────────────\n";
echo "Test Customer: {$testClient['name']}\n";
echo "Email: {$testClient['email']}\n";
echo "Phone: {$testClient['phone']}\n\n";

$clientResolver = app(ClientResolver::class);

// Test each resolution method
$hints = [
    'name' => $testClient['name'],
    'email' => $testClient['email'], 
    'phone' => $testClient['phone']
];

$resolved = $clientResolver->resolve($hints);

if ($resolved) {
    echo "✅ Client Resolution: SUCCESS\n";
    echo "Resolved Client ID: {$resolved['id']}\n";
    echo "Confidence: " . number_format($resolved['confidence'] * 100, 1) . "%\n";
    echo "Expected ID: {$testClient['expected_id']}\n";
    echo "Match: " . ($resolved['id'] == $testClient['expected_id'] ? '✅ CORRECT' : '❌ WRONG') . "\n\n";
    
    // Create a test intake with the resolved client ID
    echo "2. Creating Test Intake:\n";
    echo "──────────────────────\n";
    
    $intake = Intake::factory()->create([
        'customer_name' => $testClient['name'],
        'contact_email' => $testClient['email'],
        'contact_phone' => $testClient['phone'],
        'robaws_client_id' => (int)$resolved['id'], // Pre-resolve to test type safety
    ]);
    
    echo "Created Intake ID: {$intake->id}\n";
    echo "Pre-resolved Client ID: {$intake->robaws_client_id}\n";
    echo "Client ID Type: " . gettype($intake->robaws_client_id) . "\n\n";
    
    // Test the complete export flow
    echo "3. Testing Complete Export Flow:\n";
    echo "──────────────────────────────\n";
    
    $exportService = app(RobawsExportService::class);
    
    $extractionData = [
        'customerName' => $testClient['name'],
        'contactEmail' => $testClient['email'],
        'customerPhone' => $testClient['phone'],
        'vehicleDetails' => [
            'brand' => 'BMW',
            'model' => 'Serie 7',
            'year' => 2024,
            'price' => 50000,
        ],
        'quoteRequest' => [
            'origin' => 'Dubai, UAE',
            'destination' => 'Antwerp, Belgium',
            'service' => 'Container Shipping'
        ]
    ];
    
    echo "Attempting export with type-safe payload building...\n";
    
    try {
        $result = $exportService->exportIntake($intake, $extractionData);
        
        echo "\n📊 Export Result:\n";
        echo "──────────────\n";
        echo "Success: " . ($result['success'] ? '✅ YES' : '❌ NO') . "\n";
        
        if ($result['success']) {
            echo "Action: {$result['action']}\n";
            echo "Offer ID: {$result['quotation_id']}\n";
            echo "Duration: {$result['duration_ms']}ms\n";
            
            if (isset($result['idempotency_key'])) {
                echo "Idempotency Key: " . substr($result['idempotency_key'], 0, 16) . "...\n";
            }
            
            echo "\n🎯 SUCCESS! Client was properly recognized and offer created!\n";
            echo "✅ Client ID {$resolved['id']} was successfully linked to offer {$result['quotation_id']}\n";
            
        } else {
            echo "❌ Error: {$result['error']}\n";
            echo "Status: " . ($result['status'] ?? 'N/A') . "\n";
            
            if (isset($result['details'])) {
                echo "Details: " . json_encode($result['details'], JSON_PRETTY_PRINT) . "\n";
            }
        }
        
    } catch (Exception $e) {
        echo "❌ Export Exception: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
    
    // Clean up
    echo "\n🧹 Cleaning up test data...\n";
    $intake->delete();
    echo "Test intake deleted.\n";
    
} else {
    echo "❌ Client Resolution: FAILED\n";
    echo "Cannot proceed with export test.\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "🎯 Summary:\n";
echo "This test verifies the complete flow from client resolution\n";
echo "through type-safe payload building to actual offer creation.\n";
echo "If successful, your local environment now properly recognizes\n";
echo "clients when exporting to Robaws! 🚀\n";

<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🎯 Testing Complete Client Linking Flow (with include=client)\n";
echo "============================================================\n\n";

try {
    $apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
    
    echo "1. Verifying client 3473 exists...\n";
    echo "──────────────────────────────────\n";
    $client = $apiClient->getClientById('3473');
    
    if (!$client) {
        echo "❌ Client 3473 not found!\n";
        exit(1);
    }
    
    echo "✅ Client 3473 exists: {$client['name']} ({$client['email']})\n\n";
    
    echo "2. Testing updated getOffer method...\n";
    echo "────────────────────────────────────\n";
    
    // Test with existing offer 11720
    echo "Testing offer 11720 WITHOUT include:\n";
    $withoutInclude = $apiClient->getOffer('11720');
    if ($withoutInclude['success']) {
        $data = $withoutInclude['data'];
        echo "clientId field: " . json_encode($data['clientId'] ?? null) . "\n";
        echo "client object: " . json_encode($data['client'] ?? null) . "\n";
    }
    
    echo "\nTesting offer 11720 WITH include=client:\n";
    $withInclude = $apiClient->getOffer('11720', ['client']);
    if ($withInclude['success']) {
        $data = $withInclude['data'];
        echo "clientId field: " . json_encode($data['clientId'] ?? null) . "\n";
        echo "client object: " . json_encode($data['client'] ?? null) . "\n";
        
        if (!empty($data['client'])) {
            echo "✅ Client object is now populated!\n";
            echo "Client name from object: " . ($data['client']['name'] ?? 'N/A') . "\n";
        } else {
            echo "⚠️  Client object still null - this might indicate an API issue\n";
        }
    }
    
    echo "\n3. Creating a fresh test offer...\n";
    echo "─────────────────────────────────\n";
    
    // Create a test intake
    $testIntake = \App\Models\Intake::factory()->create([
        'customer_name' => '222 CARS',
        'contact_email' => 'info@222motors.ae',
        'robaws_client_id' => 3473
    ]);
    
    echo "Created test intake ID: {$testIntake->id}\n";
    
    // Export it
    $exportService = app(\App\Services\Robaws\RobawsExportService::class);
    $result = $exportService->exportIntake($testIntake, [
        'customerName' => '222 CARS',
        'contactEmail' => 'info@222motors.ae',
    ]);
    
    if ($result['success']) {
        $newOfferId = $result['quotation_id'];
        echo "✅ Created new offer: {$newOfferId}\n\n";
        
        echo "4. Verifying new offer with improved logic...\n";
        echo "────────────────────────────────────────────\n";
        
        $verify = $apiClient->getOffer($newOfferId, ['client']);
        
        if ($verify['success']) {
            $verifyData = $verify['data'];
            
            $linkedIdFromField = (int)($verifyData['clientId'] ?? 0);
            $linkedIdFromObject = (int)($verifyData['client']['id'] ?? 0);
            $expectedClientId = 3473;
            
            $isLinked = ($linkedIdFromField === $expectedClientId) || ($linkedIdFromObject === $expectedClientId);
            
            echo "Expected client ID: {$expectedClientId}\n";
            echo "clientId field: {$linkedIdFromField}\n";
            echo "client.id from object: {$linkedIdFromObject}\n";
            echo "Client name: " . ($verifyData['client']['name'] ?? 'N/A') . "\n";
            echo "Client linked: " . ($isLinked ? '✅ YES' : '❌ NO') . "\n\n";
            
            if ($isLinked) {
                echo "🎉 SUCCESS! Client linking is now working correctly!\n";
                echo "The verification logic will now properly detect linked clients.\n";
            } else {
                echo "❌ Issue persists. Debugging needed.\n";
            }
        } else {
            echo "❌ Failed to verify offer\n";
        }
        
    } else {
        echo "❌ Failed to create test offer: {$result['error']}\n";
    }
    
    // Cleanup
    echo "\n5. Cleaning up...\n";
    echo "─────────────────\n";
    if (isset($testIntake)) {
        $testIntake->delete();
        echo "✅ Deleted test intake\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "🏁 Test completed!\n";
    echo "The client linking verification should now work properly.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

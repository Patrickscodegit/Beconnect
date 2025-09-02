<?php

// Run via: php artisan tinker --execute="require 'debug_process_intake.php';"

use App\Models\Intake;
use App\Jobs\ProcessIntake;
use App\Services\Robaws\ClientResolver;

echo "🔍 DEBUGGING PROCESS INTAKE FAILURE\n";
echo "===================================\n\n";

// Test 1: Create test intake
echo "TEST 1: Create Test Intake\n";
echo "--------------------------\n";

$testIntake = Intake::create([
    'customer_name' => 'Carhanco',
    'contact_email' => null,
    'contact_phone' => null,
    'status' => 'pending',
    'source' => 'manual',
    'extraction_data' => [
        'contact' => [
            'name' => 'Carhanco',
            'email' => null,
            'phone' => null,
        ]
    ]
]);

echo "✅ Created intake ID: {$testIntake->id}\n\n";

// Test 2: Test ClientResolver directly
echo "TEST 2: Test ClientResolver Directly\n";
echo "------------------------------------\n";

try {
    $resolver = app(ClientResolver::class);
    $hints = ['name' => 'Carhanco'];
    $result = $resolver->resolve($hints);
    
    if ($result) {
        echo "✅ ClientResolver works\n";
        echo "   Client ID: {$result['id']}\n";
        echo "   Confidence: {$result['confidence']}\n";
    } else {
        echo "❌ ClientResolver failed\n";
    }
} catch (\Exception $e) {
    echo "❌ ClientResolver error: {$e->getMessage()}\n";
}

echo "\n";

// Test 3: Manual ProcessIntake with debugging
echo "TEST 3: Manual ProcessIntake with Debugging\n";
echo "-------------------------------------------\n";

try {
    // Load the intake for processing
    $intake = Intake::find($testIntake->id);
    
    echo "Before processing:\n";
    echo "  Status: {$intake->status}\n";
    echo "  Customer Name: " . ($intake->customer_name ?: '(null)') . "\n";
    echo "  Robaws Client ID: " . ($intake->robaws_client_id ?: '(null)') . "\n";
    echo "  Extraction Data: " . json_encode($intake->extraction_data, JSON_PRETTY_PRINT) . "\n\n";
    
    // Try client resolution on extraction data
    $extractionData = $intake->extraction_data ?? [];
    $customerName = data_get($extractionData, 'contact.name') ?? $intake->customer_name;
    
    echo "Client resolution input:\n";
    echo "  Customer Name: '{$customerName}'\n";
    
    if ($customerName) {
        $resolver = app(ClientResolver::class);
        $hints = ['name' => $customerName];
        $clientResult = $resolver->resolve($hints);
        
        if ($clientResult) {
            echo "✅ Client resolved manually\n";
            echo "   Client ID: {$clientResult['id']}\n";
            echo "   Confidence: {$clientResult['confidence']}\n";
            
            // Update intake with resolved client
            $intake->update([
                'robaws_client_id' => $clientResult['id'],
                'status' => 'processed'
            ]);
            
            echo "✅ Intake updated with client ID\n";
        } else {
            echo "❌ Manual client resolution failed\n";
        }
    } else {
        echo "❌ No customer name found\n";
    }
    
    $intake->refresh();
    echo "\nAfter processing:\n";
    echo "  Status: {$intake->status}\n";
    echo "  Robaws Client ID: " . ($intake->robaws_client_id ?: '(null)') . "\n";
    
} catch (\Exception $e) {
    echo "❌ Manual processing error: {$e->getMessage()}\n";
    echo "   File: {$e->getFile()}:{$e->getLine()}\n";
}

echo "\n🎯 DEBUGGING RESULT\n";
echo "==================\n";

$finalIntake = Intake::find($testIntake->id);
if ($finalIntake && $finalIntake->robaws_client_id) {
    echo "✅ SUCCESS: Client resolution is working\n";
    echo "   The issue might be in ProcessIntake job itself\n";
    echo "   Client ID: {$finalIntake->robaws_client_id}\n";
} else {
    echo "❌ ISSUE: Client resolution not working\n";
    echo "   Need to investigate ClientResolver or data structure\n";
}

// Cleanup
echo "\n🧹 Cleaning up...\n";
if (isset($testIntake)) {
    $testIntake->delete();
    echo "✅ Test data cleaned up\n";
}

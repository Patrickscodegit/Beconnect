<?php

// Run via: php artisan tinker --execute="require 'debug_process_intake_detailed.php';"

use App\Models\Intake;
use App\Jobs\ProcessIntake;
use App\Services\Robaws\ClientResolver;

echo "🔍 DETAILED PROCESS INTAKE DEBUGGING\n";
echo "====================================\n\n";

// Create test intake without files (which might be causing the issue)
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

echo "✅ Created intake ID: {$testIntake->id}\n";
echo "✅ Files count: " . $testIntake->files->count() . "\n\n";

// Let's manually step through the ProcessIntake logic
echo "MANUAL STEP-BY-STEP PROCESS:\n";
echo "----------------------------\n";

try {
    // Step 1: Check files
    $files = $testIntake->files;
    echo "1️⃣ Files check:\n";
    echo "   Files count: {$files->count()}\n";
    
    if ($files->isEmpty()) {
        echo "   ⚠️  No files - ProcessIntake would set status to 'failed' and return\n";
        echo "   This is why our test is failing!\n\n";
        
        // Let's modify the test to have extraction data ready (as if files were processed)
        echo "2️⃣ Simulating file processing with existing extraction data:\n";
        
        $payload = (array) ($testIntake->extraction_data ?? []);
        echo "   Payload: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n";
        
        // Step 3: Contact data merge
        $contactData = array_merge(
            (array) data_get($payload, 'contact', []),
            array_filter([
                'name' => $testIntake->customer_name,
                'email' => $testIntake->contact_email,
                'phone' => $testIntake->contact_phone,
            ])
        );
        
        echo "3️⃣ Contact data merge:\n";
        echo "   Merged contact: " . json_encode($contactData, JSON_PRETTY_PRINT) . "\n";
        
        // Update payload
        $payload['contact'] = $contactData;
        
        $testIntake->update([
            'extraction_data' => $payload,
            'customer_name' => $contactData['name'] ?? $testIntake->customer_name,
            'contact_email' => $contactData['email'] ?? $testIntake->contact_email,
            'contact_phone' => $contactData['phone'] ?? $testIntake->contact_phone,
        ]);
        
        echo "4️⃣ Client resolution:\n";
        $resolver = app(\App\Services\Robaws\ClientResolver::class);
        
        $hints = [
            'id'    => null,  // no metadata
            'email' => $testIntake->contact_email,
            'phone' => $testIntake->contact_phone,
            'name'  => $testIntake->customer_name,
        ];
        
        echo "   Hints: " . json_encode($hints, JSON_PRETTY_PRINT) . "\n";
        
        if ($hit = $resolver->resolve($hints)) {
            echo "   ✅ Client resolved!\n";
            echo "   Client ID: {$hit['id']}\n";
            echo "   Confidence: {$hit['confidence']}\n";
            
            $testIntake->robaws_client_id = (string)$hit['id'];
            $testIntake->status = 'processed';
            $testIntake->save();
            
            echo "   ✅ Intake updated successfully\n";
        } else {
            echo "   ❌ Client resolution failed\n";
            
            // Fallback validation
            $hasEmail = filter_var($testIntake->contact_email, FILTER_VALIDATE_EMAIL);
            $hasPhone = !empty($testIntake->contact_phone);
            $status = ($hasEmail || $hasPhone) ? 'processed' : 'needs_contact';
            
            echo "   Fallback validation:\n";
            echo "     Has email: " . ($hasEmail ? 'YES' : 'NO') . "\n";
            echo "     Has phone: " . ($hasPhone ? 'YES' : 'NO') . "\n";
            echo "     Status: {$status}\n";
            
            $testIntake->status = $status;
            $testIntake->save();
        }
    }
    
    // Final state
    $testIntake->refresh();
    echo "\n🎯 FINAL STATE:\n";
    echo "   Status: {$testIntake->status}\n";
    echo "   Robaws Client ID: " . ($testIntake->robaws_client_id ?: '(null)') . "\n";
    echo "   Customer Name: " . ($testIntake->customer_name ?: '(null)') . "\n";
    
} catch (\Exception $e) {
    echo "❌ Error: {$e->getMessage()}\n";
    echo "   File: {$e->getFile()}:{$e->getLine()}\n";
}

echo "\n📝 ANALYSIS:\n";
echo "============\n";
echo "The ProcessIntake job fails because it requires files to be present.\n";
echo "When no files are found, it immediately sets status to 'failed' and returns.\n";
echo "This is the root cause of the issue with manual/UI-created intakes.\n";

// Cleanup
$testIntake->delete();
echo "\n✅ Cleanup completed\n";

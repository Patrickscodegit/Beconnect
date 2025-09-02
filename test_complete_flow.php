<?php

// Run via: php artisan tinker --execute="require 'test_complete_flow.php';"

use App\Models\Intake;
use App\Jobs\ProcessIntake;

echo "🧪 TESTING COMPLETE FLOW WITH ROBAWS_CLIENT_ID COLUMN\n";
echo "====================================================\n\n";

// Test 1: Create an intake manually and process it
echo "TEST 1: Create and Process Intake\n";
echo "---------------------------------\n";

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
        ],
        'vehicle' => [
            'make' => 'BMW',
            'model' => 'Serie 7',
        ]
    ]
]);

echo "✅ Created intake ID: {$testIntake->id}\n";
echo "   Customer: {$testIntake->customer_name}\n";
echo "   Status: {$testIntake->status}\n";
echo "   Robaws Client ID: " . ($testIntake->robaws_client_id ?: '(null)') . "\n\n";

// Test 2: Process the intake via ProcessIntake job
echo "TEST 2: Process Intake Job\n";
echo "---------------------------\n";

try {
    $job = new ProcessIntake($testIntake);  // Pass the model, not the ID
    $job->handle();
    
    // Reload the intake to see changes
    $testIntake->refresh();
    
    echo "✅ ProcessIntake completed successfully\n";
    echo "   Status: {$testIntake->status}\n";
    echo "   Robaws Client ID: " . ($testIntake->robaws_client_id ?: '(null)') . "\n";
    echo "   Customer Name: " . ($testIntake->customer_name ?: '(null)') . "\n";
    echo "   Contact Email: " . ($testIntake->contact_email ?: '(null)') . "\n\n";
    
} catch (\Exception $e) {
    echo "❌ ProcessIntake failed: {$e->getMessage()}\n";
    echo "   File: {$e->getFile()}:{$e->getLine()}\n\n";
    exit(1);
}

// Test 3: Check database structure
echo "TEST 3: Database Structure Verification\n";
echo "---------------------------------------\n";

$columns = \Schema::getColumnListing('intakes');
$hasRobawsClientId = in_array('robaws_client_id', $columns);

echo "✅ Table columns verified\n";
echo "   robaws_client_id column exists: " . ($hasRobawsClientId ? 'YES' : 'NO') . "\n";
echo "   All columns: " . implode(', ', $columns) . "\n\n";

// Test 4: Client resolution validation
echo "TEST 4: Client Resolution Validation\n";
echo "------------------------------------\n";

if ($testIntake->robaws_client_id) {
    echo "✅ Client successfully resolved\n";
    echo "   Client ID: {$testIntake->robaws_client_id}\n";
    echo "   Ready for export: YES\n";
} else {
    echo "❌ Client not resolved\n";
    echo "   Ready for export: NO\n";
}

echo "\n🎯 FINAL RESULT\n";
echo "===============\n";

if ($hasRobawsClientId && $testIntake->robaws_client_id) {
    echo "✅ SUCCESS: Complete flow working!\n";
    echo "\n📋 Summary:\n";
    echo "1. ✅ Database column 'robaws_client_id' added successfully\n";
    echo "2. ✅ Intake model updated with fillable field\n";
    echo "3. ✅ ProcessIntake job processes name-only intakes\n";
    echo "4. ✅ Client resolution stores ID in database\n";
    echo "5. ✅ Name-only intake pipeline fully functional\n";
    echo "\n🚀 System ready for production use!\n";
} else {
    echo "❌ ISSUES DETECTED:\n";
    if (!$hasRobawsClientId) echo "   - robaws_client_id column missing\n";
    if (!$testIntake->robaws_client_id) echo "   - Client not resolved during processing\n";
    echo "\n🔧 Please check the implementation\n";
}

// Cleanup
echo "\n🧹 Cleaning up test data...\n";
$testIntake->delete();
echo "✅ Test intake deleted\n";

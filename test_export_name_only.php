<?php

// Run via: php artisan tinker --execute="require 'test_export_name_only.php';"

use App\Services\Robaws\RobawsExportService;
use App\Models\Intake;

echo "🧪 TESTING EXPORT WITH NAME-ONLY DATA\n";
echo "=====================================\n\n";

// Test extraction data with only customer name (no email/phone)
$extractionData = [
    'contact' => [
        'name' => 'Carhanco',
        'email' => null,
        'phone' => null,
    ],
    'vehicle' => [
        'make' => 'BMW',
        'model' => 'Serie 7',
        'year' => '2024',
    ],
    'shipping' => [
        'origin' => 'Hamburg',
        'destination' => 'New York',
    ]
];

echo "🔍 Test Data:\n";
echo "  Customer Name: '{$extractionData['contact']['name']}'\n";
echo "  Contact Email: " . ($extractionData['contact']['email'] ?: '(empty)') . "\n";
echo "  Contact Phone: " . ($extractionData['contact']['phone'] ?: '(empty)') . "\n\n";

echo "TEST 1: Legacy resolveClientId Method\n";
echo "------------------------------------\n";

$exportService = app(RobawsExportService::class);
$clientId = $exportService->resolveClientId($extractionData);

if ($clientId) {
    echo "✅ SUCCESS: Client resolved via legacy method\n";
    echo "   Client ID: {$clientId}\n";
} else {
    echo "❌ FAILED: Legacy method could not resolve client\n";
    exit(1);
}

echo "\nTEST 2: Main Export Flow Simulation\n";
echo "-----------------------------------\n";

// Create a test intake to simulate the export flow
$testIntake = new Intake([
    'customer_name' => 'Carhanco',
    'customer_email' => null,
    'customer_phone' => null,
    'status' => 'processed',
    'robaws_client_id' => null, // Force resolution during export
]);

// Simulate extraction data
$testIntake->extraction_data = $extractionData;

echo "Simulating export flow...\n";

// Get client resolution variables (from exportIntake method logic)
$customerName = data_get($extractionData, 'contact.name') ?? $testIntake->customer_name;
$customerEmail = data_get($extractionData, 'contact.email') ?? $testIntake->customer_email;
$customerPhone = data_get($extractionData, 'contact.phone') ?? $testIntake->customer_phone;

echo "  Resolved variables:\n";
echo "    Customer Name: '{$customerName}'\n";
echo "    Customer Email: " . ($customerEmail ?: '(null)') . "\n";
echo "    Customer Phone: " . ($customerPhone ?: '(null)') . "\n\n";

// Test the findClientId method (used in main export flow)
$apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
$resolvedClientId = $apiClient->findClientId($customerName, $customerEmail, $customerPhone);

if ($resolvedClientId) {
    echo "✅ SUCCESS: Client resolved via main export flow\n";
    echo "   Client ID: {$resolvedClientId}\n";
} else {
    echo "❌ FAILED: Main export flow could not resolve client\n";
    exit(1);
}

echo "\nTEST 3: Export Job Validation\n";
echo "-----------------------------\n";

// Simulate ExportIntakeToRobawsJob logic
if (!empty($testIntake->robaws_client_id)) {
    echo "✅ Pre-resolved client ID found: {$testIntake->robaws_client_id}\n";
    echo "   Export would proceed immediately\n";
} else {
    echo "🔄 No pre-resolved client, would use fallback resolution\n";
    
    // Test fallback resolution (legacy method)
    $fallbackClientId = $exportService->resolveClientId($extractionData);
    
    if ($fallbackClientId) {
        echo "✅ Fallback resolution successful: {$fallbackClientId}\n";
        echo "   Export would proceed after storing client ID\n";
    } else {
        echo "❌ Fallback resolution failed\n";
        echo "   Export would fail with: 'Could not resolve client in Robaws'\n";
        exit(1);
    }
}

echo "\n🎯 FINAL RESULT\n";
echo "===============\n";
echo "✅ SUCCESS: Name-only export is fully functional!\n";
echo "\n📋 Summary:\n";
echo "1. ✅ Legacy resolveClientId method now supports name-only resolution\n";
echo "2. ✅ Main export flow (findClientId) already supported name-only resolution\n";
echo "3. ✅ Export job validation will work with name-only data\n";
echo "4. ✅ 'Carhanco' alone is sufficient for successful export to Robaws\n";
echo "\n🚀 Export pipeline ready for name-only intakes!\n";

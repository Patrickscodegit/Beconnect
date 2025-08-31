<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->boot();

use App\Models\Document;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;

echo "🚀 Testing surgical fixes for routing persistence...\n\n";

// Find BMW document with customer reference
$document = Document::whereNotNull('robaws_quotation_data')
    ->whereJsonContains('robaws_quotation_data->customer_reference', 'EXP RORO')
    ->first();

if (!$document) {
    echo "❌ No BMW test document found\n";
    exit;
}

echo "📄 Working with document: {$document->filename}\n";

// Check original data
$original = $document->robaws_quotation_data;
echo "   Original routing:\n";
echo "     customer_reference: " . ($original['customer_reference'] ?? 'NULL') . "\n";
echo "     POR: " . ($original['por'] ?? 'NULL') . "\n";
echo "     POL: " . ($original['pol'] ?? 'NULL') . "\n";
echo "     POD: " . ($original['pod'] ?? 'NULL') . "\n";
echo "     robaws_quotation_id: " . ($document->robaws_quotation_id ?? 'NULL') . "\n";

// Clear routing to test backfill
$testData = $original;
$testData['por'] = null;
$testData['pol'] = null;
$testData['pod'] = null;

// Also clear robaws_quotation_id to allow reprocessing
$document->update([
    'robaws_quotation_data' => $testData,
    'robaws_quotation_id' => null,
    'robaws_sync_status' => 'needs_review'
]);

echo "\n🔄 Cleared routing & quotation ID, testing service...";

// Test service with extraction data
$extraction = $document->extractions()->latest()->first();
if ($extraction && $extraction->extracted_data) {
    $extractedData = is_array($extraction->extracted_data) 
        ? $extraction->extracted_data 
        : json_decode($extraction->extracted_data, true);
        
    $service = app(EnhancedRobawsIntegrationService::class);
    $result = $service->processDocument($document, $extractedData);
    
    echo ' ' . ($result ? '✅ SUCCESS' : '❌ FAILED') . "\n";
    
    // Check final data
    $document->refresh();
    $final = $document->robaws_quotation_data;
    echo "\n📊 Final routing after service:\n";
    echo "     customer_reference: " . ($final['customer_reference'] ?? 'NULL') . "\n";
    echo "     POR: " . ($final['por'] ?? 'NULL') . "\n";
    echo "     POL: " . ($final['pol'] ?? 'NULL') . "\n";
    echo "     POD: " . ($final['pod'] ?? 'NULL') . "\n";
    echo "     sync_status: " . $document->robaws_sync_status . "\n";
    
} else {
    echo " ❌ No extraction data found\n";
}

echo "\n✅ Test completed!\n";

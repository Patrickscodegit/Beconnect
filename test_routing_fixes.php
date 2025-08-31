<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->boot();

use App\Models\Document;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;

echo "🔧 Testing surgical routing persistence fixes...\n\n";

// Find BMW document
$document = Document::whereNotNull('robaws_quotation_data')
    ->whereJsonContains('robaws_quotation_data->customer_reference', 'EXP RORO')
    ->first();

if (!$document) {
    echo "❌ No BMW test document found\n";
    exit;
}

echo "📄 Document: {$document->filename}\n";

// Clear routing and quotation ID for clean test
$original = $document->robaws_quotation_data;
$testData = $original;
$testData['por'] = null;
$testData['pol'] = null; 
$testData['pod'] = null;

$document->update([
    'robaws_quotation_data' => $testData,
    'robaws_quotation_id' => null,
    'robaws_sync_status' => 'needs_review'
]);

echo "🔄 Cleared routing and quotation ID\n";

// Test with extraction data
$extraction = $document->extractions()->latest()->first();
if ($extraction && $extraction->extracted_data) {
    $extractedData = is_array($extraction->extracted_data) 
        ? $extraction->extracted_data 
        : json_decode($extraction->extracted_data, true);
        
    $service = app(EnhancedRobawsIntegrationService::class);
    $result = $service->processDocument($document, $extractedData);
    
    echo "⚙️  Processing result: " . ($result ? "✅ SUCCESS" : "❌ FAILED") . "\n";
    
    // Check final data
    $document->refresh();
    $final = $document->robaws_quotation_data;
    echo "\n📊 Final routing:\n";
    echo "  customer_reference: " . ($final['customer_reference'] ?? 'NULL') . "\n";
    echo "  POR: " . ($final['por'] ?? 'NULL') . "\n";
    echo "  POL: " . ($final['pol'] ?? 'NULL') . "\n";
    echo "  POD: " . ($final['pod'] ?? 'NULL') . "\n";
    echo "  sync_status: {$document->robaws_sync_status}\n";
    
    if (!empty($final['por']) && !empty($final['pod'])) {
        echo "\n🎉 SUCCESS: Routing backfill worked!\n";
    } else {
        echo "\n❌ ISSUE: Routing still missing\n";
    }
} else {
    echo "❌ No extraction data found\n";
}

echo "\n✅ Test completed!\n";

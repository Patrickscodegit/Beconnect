<?php

require __DIR__ . '/vendor/autoload.php';

use App\Models\Document;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use App\Services\RobawsIntegration\JsonFieldMapper;

echo "🔍 Debug Service Flow Step by Step\n";
echo "==================================\n\n";

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $document = Document::where('id', 1)->first();
    
    if (!$document) {
        echo "❌ Document not found\n";
        exit(1);
    }

    echo "📄 Document: {$document->filename}\n\n";

    // Test the service flow step by step
    $service = app(EnhancedRobawsIntegrationService::class);
    $mapper = app(JsonFieldMapper::class);
    $mapper->reloadConfiguration();
    
    // Get extracted data (what the service gets)
    $extraction = $document->extractions()->latest()->first();
    if (!$extraction || !$extraction->extracted_data) {
        echo "❌ No extraction data\n";
        exit(1);
    }
    
    $extractedData = is_array($extraction->extracted_data) 
        ? $extraction->extracted_data 
        : json_decode($extraction->extracted_data, true);
    
    echo "📊 Step 1: Extracted data from DB:\n";
    echo "   Keys: " . json_encode(array_keys($extractedData)) . "\n";
    echo "   customer_reference: " . json_encode($extractedData['customer_reference'] ?? 'NOT_FOUND') . "\n\n";
    
    // Get existing robaws data
    $existingRobawsData = $document->robaws_quotation_data ?? [];
    echo "📊 Step 2: Existing robaws data:\n";
    echo "   customer_reference: " . json_encode($existingRobawsData['customer_reference'] ?? 'NOT_FOUND') . "\n";
    echo "   por: " . json_encode($existingRobawsData['por'] ?? 'NOT_FOUND') . "\n";
    echo "   pol: " . json_encode($existingRobawsData['pol'] ?? 'NOT_FOUND') . "\n";
    echo "   pod: " . json_encode($existingRobawsData['pod'] ?? 'NOT_FOUND') . "\n\n";
    
    // Merge data (what should happen in service)
    $enrichedData = array_merge($extractedData, $existingRobawsData);
    echo "📊 Step 3: Enriched data after merge:\n";
    echo "   customer_reference: " . json_encode($enrichedData['customer_reference'] ?? 'NOT_FOUND') . "\n\n";
    
    // Map fields
    echo "🔄 Step 4: Map fields\n";
    $mapped = $mapper->mapFields($enrichedData);
    echo "   customer_reference: " . json_encode($mapped['customer_reference'] ?? 'NOT_FOUND') . "\n";
    echo "   por: " . json_encode($mapped['por'] ?? 'NOT_FOUND') . "\n";
    echo "   pol: " . json_encode($mapped['pol'] ?? 'NOT_FOUND') . "\n";
    echo "   pod: " . json_encode($mapped['pod'] ?? 'NOT_FOUND') . "\n\n";
    
    // Test the actual service method
    echo "⚡ Step 5: Run actual service\n";
    $success = $service->processDocumentFromExtraction($document);
    echo "   Success: " . ($success ? 'YES' : 'NO') . "\n\n";
    
    // Check final result
    $document->refresh();
    $finalData = $document->robaws_quotation_data ?? [];
    echo "📈 Step 6: Final stored data:\n";
    echo "   customer_reference: " . json_encode($finalData['customer_reference'] ?? 'NOT_FOUND') . "\n";
    echo "   por: " . json_encode($finalData['por'] ?? 'NOT_FOUND') . "\n";
    echo "   pol: " . json_encode($finalData['pol'] ?? 'NOT_FOUND') . "\n";
    echo "   pod: " . json_encode($finalData['pod'] ?? 'NOT_FOUND') . "\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

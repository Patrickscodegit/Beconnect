<?php

require __DIR__ . '/vendor/autoload.php';

use App\Models\Document;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use App\Services\RobawsIntegration\JsonFieldMapper;

echo "🚀 Testing Complete Sources-First Mapping with Customer Reference Normalization\n";
echo "==============================================================================\n\n";

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    // Find a specific document to test with
    $document = Document::where('id', 1)->first();

    if (!$document) {
        echo "❌ No test document found\n";
        exit(1);
    }

    echo "📄 Testing with document: {$document->filename}\n";
    echo "   Document ID: {$document->id}\n";
    echo "   Current status: {$document->robaws_sync_status}\n\n";

    // Get current data before processing
    $originalData = $document->robaws_quotation_data ?? [];
    echo "📊 Current data state:\n";
    echo "   customer_reference: " . ($originalData['customer_reference'] ?? 'NULL') . "\n";
    echo "   POR: " . ($originalData['por'] ?? 'NULL') . "\n";
    echo "   POL: " . ($originalData['pol'] ?? 'NULL') . "\n";
    echo "   POD: " . ($originalData['pod'] ?? 'NULL') . "\n";
    echo "   cargo: " . ($originalData['cargo'] ?? 'NULL') . "\n\n";

    // Reload configuration to ensure latest changes
    echo "🔄 Reloading field mapping configuration...\n";
    app(JsonFieldMapper::class)->reloadConfiguration();

    // Process the document with enhanced service
    echo "⚡ Processing document with EnhancedRobawsIntegrationService...\n";
    $enhancedService = app(EnhancedRobawsIntegrationService::class);
    $success = $enhancedService->processDocumentFromExtraction($document);

    echo "   Process result: " . ($success ? "✅ SUCCESS" : "❌ FAILED") . "\n\n";

    // Get updated data
    $document->refresh();
    $updatedData = $document->robaws_quotation_data ?? [];

    echo "📈 Updated data state:\n";
    echo "   customer_reference: " . ($updatedData['customer_reference'] ?? 'NULL') . "\n";
    echo "   POR: " . ($updatedData['por'] ?? 'NULL') . "\n";
    echo "   POL: " . ($updatedData['pol'] ?? 'NULL') . "\n";
    echo "   POD: " . ($updatedData['pod'] ?? 'NULL') . "\n";
    echo "   cargo: " . ($updatedData['cargo'] ?? 'NULL') . "\n";
    echo "   sync_status: " . ($document->robaws_sync_status ?? 'NULL') . "\n\n";

    // Analyze the results
    echo "🔍 Analysis:\n";
    
    // Check customer_reference preservation
    $customerRef = $updatedData['customer_reference'] ?? '';
    if (strpos($customerRef, 'BRU') !== false && strpos($customerRef, 'JED') !== false) {
        echo "   ✅ customer_reference contains routing codes (BRU/JED)\n";
    } elseif (strpos($customerRef, 'EXP RORO') !== false) {
        echo "   ✅ customer_reference built from template\n";
    } else {
        echo "   ❌ customer_reference missing or malformed\n";
    }

    // Check routing backfill
    $hasRouting = !empty($updatedData['por']) && !empty($updatedData['pol']) && !empty($updatedData['pod']);
    if ($hasRouting) {
        echo "   ✅ Routing fields populated by backfill\n";
    } else {
        echo "   ❌ Routing fields still missing\n";
    }

    // Check cargo formatting
    $cargo = $updatedData['cargo'] ?? '';
    if (!empty($cargo) && !str_contains($cargo, 'x ()')) {
        echo "   ✅ Cargo formatted correctly\n";
    } else {
        echo "   ⚠️  Cargo formatting may need review\n";
    }

    echo "\n✅ Test completed successfully!\n";

} catch (Exception $e) {
    echo "❌ Error during test: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

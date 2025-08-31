<?php

require __DIR__ . '/vendor/autoload.php';

use App\Models\Document;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use App\Services\RobawsIntegration\JsonFieldMapper;

echo "🔍 Debug Complete Processing Flow\n";
echo "=================================\n\n";

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

    // Get the mapper and enhance service  
    $mapper = app(JsonFieldMapper::class);
    $mapper->reloadConfiguration();
    
    $service = app(EnhancedRobawsIntegrationService::class);
    
    // Get extracted data
    $extractedData = $document->ai_extracted_data ?? [];
    
    echo "📊 Step 1: Raw extracted data (relevant fields):\n";
    echo "   customer_reference: " . json_encode($extractedData['customer_reference'] ?? null) . "\n";
    echo "   concerning: " . json_encode($extractedData['concerning'] ?? null) . "\n";
    echo "   title: " . json_encode($extractedData['title'] ?? null) . "\n\n";
    
    // Test the mapping process
    echo "🔄 Step 2: Field mapping process...\n";
    $mappedData = $mapper->mapDocumentData($extractedData);
    
    echo "   customer_reference: " . json_encode($mappedData['customer_reference'] ?? null) . "\n";
    echo "   por: " . json_encode($mappedData['por'] ?? null) . "\n";
    echo "   pol: " . json_encode($mappedData['pol'] ?? null) . "\n";
    echo "   pod: " . json_encode($mappedData['pod'] ?? null) . "\n\n";
    
    // Test normalization
    echo "🔧 Step 3: Normalize blank values...\n";
    $reflection = new ReflectionClass($service);
    $normalizeMethod = $reflection->getMethod('normalizeBlankValues');
    $normalizeMethod->setAccessible(true);
    
    $normalizedData = $normalizeMethod->invoke($service, $mappedData);
    
    echo "   customer_reference: " . json_encode($normalizedData['customer_reference'] ?? null) . "\n";
    echo "   por: " . json_encode($normalizedData['por'] ?? null) . "\n";
    echo "   pol: " . json_encode($normalizedData['pol'] ?? null) . "\n";
    echo "   pod: " . json_encode($normalizedData['pod'] ?? null) . "\n\n";
    
    // Test backfill
    echo "⚡ Step 4: Backfill routing from text...\n";
    $backfillMethod = $reflection->getMethod('backfillRoutingFromText');
    $backfillMethod->setAccessible(true);
    
    $finalData = $backfillMethod->invoke($service, $normalizedData, $extractedData);
    
    echo "   customer_reference: " . json_encode($finalData['customer_reference'] ?? null) . "\n";
    echo "   por: " . json_encode($finalData['por'] ?? null) . "\n";
    echo "   pol: " . json_encode($finalData['pol'] ?? null) . "\n";
    echo "   pod: " . json_encode($finalData['pod'] ?? null) . "\n\n";
    
    // Check needs conditions
    echo "🔍 Debugging needs conditions:\n";
    echo "   empty(por): " . (empty($normalizedData['por']) ? 'true' : 'false') . "\n";
    echo "   empty(pol): " . (empty($normalizedData['pol']) ? 'true' : 'false') . "\n";
    echo "   empty(pod): " . (empty($normalizedData['pod']) ? 'true' : 'false') . "\n";
    echo "   por === null: " . (($normalizedData['por'] ?? 'undefined') === null ? 'true' : 'false') . "\n";
    echo "   pol === null: " . (($normalizedData['pol'] ?? 'undefined') === null ? 'true' : 'false') . "\n";
    echo "   pod === null: " . (($normalizedData['pod'] ?? 'undefined') === null ? 'true' : 'false') . "\n\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

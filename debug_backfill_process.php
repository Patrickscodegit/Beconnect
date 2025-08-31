<?php

require __DIR__ . '/vendor/autoload.php';

use App\Models\Document;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;

echo "🔍 Debug Routing Backfill Process\n";
echo "================================\n\n";

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    // Get the BMW document
    $document = Document::where('id', 1)->first();
    
    if (!$document) {
        echo "❌ Document not found\n";
        exit(1);
    }

    echo "📄 Document: {$document->filename}\n\n";

    // Get extracted data to see what text is available for backfill
    $extractedData = $document->ai_extracted_data ?? [];
    
    echo "📝 Extracted data for backfill scanning:\n";
    echo "   Subject: " . ($extractedData['email_metadata']['subject'] ?? 'NULL') . "\n";
    echo "   Concerning: " . ($extractedData['concerning'] ?? 'NULL') . "\n";
    echo "   Description: " . ($extractedData['description'] ?? 'NULL') . "\n";
    echo "   Title: " . ($extractedData['title'] ?? 'NULL') . "\n\n";

    // Check current robaws data
    $robawsData = $document->robaws_quotation_data ?? [];
    echo "🎯 Current robaws routing data:\n";
    echo "   customer_reference: " . ($robawsData['customer_reference'] ?? 'NULL') . "\n";
    echo "   POR: " . ($robawsData['por'] ?? 'NULL') . "\n";
    echo "   POL: " . ($robawsData['pol'] ?? 'NULL') . "\n";
    echo "   POD: " . ($robawsData['pod'] ?? 'NULL') . "\n\n";

    // Test the backfill method directly
    echo "🔬 Testing backfill method directly...\n";
    $service = app(EnhancedRobawsIntegrationService::class);

    // Use reflection to access the private method
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('backfillRoutingFromText');
    $method->setAccessible(true);

    $testData = [
        'customer_reference' => 'EXP RORO - BRU - JED - BMW Série 7',
        'por' => null,
        'pol' => null,
        'pod' => null
    ];

    $result = $method->invoke($service, $testData, $extractedData);
    
    echo "   Input data: " . json_encode($testData, JSON_PRETTY_PRINT) . "\n";
    echo "   Result data: " . json_encode($result, JSON_PRETTY_PRINT) . "\n\n";

    if ($result['por'] || $result['pol'] || $result['pod']) {
        echo "✅ Backfill working - codes found!\n";
    } else {
        echo "❌ Backfill not finding codes - need to debug extraction logic\n";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

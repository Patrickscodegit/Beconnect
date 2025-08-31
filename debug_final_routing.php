<?php

require __DIR__ . '/vendor/autoload.php';

use App\Models\Document;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use App\Services\RobawsIntegration\JsonFieldMapper;
use Illuminate\Support\Facades\Log;

echo "🔍 Final Debug - Why Routing Still NULL\n";
echo "======================================\n\n";

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

    // Get the service and test step by step
    $mapper = app(JsonFieldMapper::class);
    $mapper->reloadConfiguration();
    
    $service = app(EnhancedRobawsIntegrationService::class);
    
    // Get extracted data
    $extractedData = $document->ai_extracted_data ?? [];
    
    echo "📊 Step 1: Map fields\n";
    // Include existing robaws data as sources to preserve existing values
    $existingRobawsData = $document->robaws_quotation_data ?? [];
    $enrichedData = array_merge($extractedData, $existingRobawsData);
    
    $mapped = $mapper->mapFields($enrichedData);
    echo "   customer_reference: " . json_encode($mapped['customer_reference'] ?? null) . "\n";
    echo "   por: " . json_encode($mapped['por'] ?? null) . "\n";
    echo "   pol: " . json_encode($mapped['pol'] ?? null) . "\n";
    echo "   pod: " . json_encode($mapped['pod'] ?? null) . "\n\n";
    
    // Test normalize
    echo "🔧 Step 2: Normalize blanks\n";
    $reflection = new ReflectionClass($service);
    $normalizeMethod = $reflection->getMethod('normalizeBlankValues');
    $normalizeMethod->setAccessible(true);
    $normalized = $normalizeMethod->invoke($service, $mapped);
    
    echo "   customer_reference: " . json_encode($normalized['customer_reference'] ?? null) . "\n";
    echo "   por: " . json_encode($normalized['por'] ?? null) . "\n";
    echo "   pol: " . json_encode($normalized['pol'] ?? null) . "\n";
    echo "   pod: " . json_encode($normalized['pod'] ?? null) . "\n\n";
    
    // Test isBlank
    echo "🔍 Step 3: Test isBlank method\n";
    $isBlankMethod = $reflection->getMethod('isBlank');
    $isBlankMethod->setAccessible(true);
    
    echo "   isBlank(null): " . ($isBlankMethod->invoke($service, null) ? 'true' : 'false') . "\n";
    echo "   isBlank(''): " . ($isBlankMethod->invoke($service, '') ? 'true' : 'false') . "\n";
    echo "   isBlank('NULL'): " . ($isBlankMethod->invoke($service, 'NULL') ? 'true' : 'false') . "\n";
    echo "   isBlank(por): " . ($isBlankMethod->invoke($service, $normalized['por'] ?? null) ? 'true' : 'false') . "\n";
    echo "   isBlank(pol): " . ($isBlankMethod->invoke($service, $normalized['pol'] ?? null) ? 'true' : 'false') . "\n";
    echo "   isBlank(pod): " . ($isBlankMethod->invoke($service, $normalized['pod'] ?? null) ? 'true' : 'false') . "\n\n";
    
    // Test backfill
    echo "⚡ Step 4: Test backfill\n";
    $backfillMethod = $reflection->getMethod('backfillRoutingFromText');
    $backfillMethod->setAccessible(true);
    
    $result = $backfillMethod->invoke($service, $normalized, $extractedData);
    
    echo "   customer_reference: " . json_encode($result['customer_reference'] ?? null) . "\n";
    echo "   por: " . json_encode($result['por'] ?? null) . "\n";
    echo "   pol: " . json_encode($result['pol'] ?? null) . "\n";
    echo "   pod: " . json_encode($result['pod'] ?? null) . "\n\n";
    
    // Test IATA extraction directly
    echo "🔬 Step 5: Test IATA extraction\n";
    $extractMethod = $reflection->getMethod('extractIataCodes');
    $extractMethod->setAccessible(true);
    
    $testText = $normalized['customer_reference'] ?? '';
    $codes = $extractMethod->invoke($service, $testText);
    echo "   Text: '$testText'\n";
    echo "   Codes found: " . json_encode($codes) . "\n";
    
    // Test code to city mapping
    $codeToCity = $reflection->getMethod('codeToCity');
    $codeToCity->setAccessible(true);
    
    foreach ($codes as $code) {
        $city = $codeToCity->invoke($service, $code);
        echo "   $code -> " . ($city ?? 'NULL') . "\n";
    }
    
    echo "\n✅ Debug completed!\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

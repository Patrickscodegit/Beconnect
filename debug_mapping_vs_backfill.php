<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;

echo "🔍 Debugging field mapping vs routing backfill...\n\n";

$doc = Document::latest()->first();
if (!$doc) {
    echo "❌ No documents found\n";
    exit;
}

echo "📄 Working with document: {$doc->filename}\n";

// Set up test data with enhanced customer reference
$extractedData = [
    'customer_reference' => 'EXP RORO - BRU - JED - BMW Série 7',
    'JSON' => json_encode(['test' => 'data'])
];

echo "   Test extracted data:\n";
echo "     customer_reference: " . $extractedData['customer_reference'] . "\n\n";

try {
    // Test field mapping first
    $fieldMapper = app(\App\Services\RobawsIntegration\JsonFieldMapper::class);
    $mappedData = $fieldMapper->mapFields($extractedData);
    
    echo "1️⃣ After field mapping:\n";
    echo "   customer: " . ($mappedData['customer'] ?? 'NULL') . "\n";
    echo "   customer_reference: " . ($mappedData['customer_reference'] ?? 'NULL') . "\n";
    echo "   por: '" . ($mappedData['por'] ?? 'NULL') . "' (empty? " . (empty($mappedData['por'] ?? null) ? 'YES' : 'NO') . ")\n";
    echo "   pol: '" . ($mappedData['pol'] ?? 'NULL') . "' (empty? " . (empty($mappedData['pol'] ?? null) ? 'YES' : 'NO') . ")\n";
    echo "   pod: '" . ($mappedData['pod'] ?? 'NULL') . "' (empty? " . (empty($mappedData['pod'] ?? null) ? 'YES' : 'NO') . ")\n\n";
    
    // Now test backfill
    $service = app(\App\Services\RobawsIntegration\EnhancedRobawsIntegrationService::class);
    $reflection = new ReflectionClass($service);
    $backfillMethod = $reflection->getMethod('backfillRoutingFromText');
    $backfillMethod->setAccessible(true);
    
    $backfilledData = $backfillMethod->invoke($service, $mappedData, $extractedData);
    
    echo "2️⃣ After routing backfill:\n";
    echo "   customer: " . ($backfilledData['customer'] ?? 'NULL') . "\n";
    echo "   customer_reference: " . ($backfilledData['customer_reference'] ?? 'NULL') . "\n";
    echo "   por: " . ($backfilledData['por'] ?? 'NULL') . "\n";
    echo "   pol: " . ($backfilledData['pol'] ?? 'NULL') . "\n";
    echo "   pod: " . ($backfilledData['pod'] ?? 'NULL') . "\n";
    
    // Compare the data
    $mappedPor = $mappedData['por'] ?? null;
    $backfilledPor = $backfilledData['por'] ?? null;
    $mappedPod = $mappedData['pod'] ?? null;
    $backfilledPod = $backfilledData['pod'] ?? null;
    
    if ($mappedPor !== $backfilledPor) {
        echo "\n✅ Backfill modified POR: '{$mappedPor}' → '{$backfilledPor}'\n";
    } else {
        echo "\n❌ Backfill did not modify POR\n";
    }
    
    if ($mappedPod !== $backfilledPod) {
        echo "✅ Backfill modified POD: '{$mappedPod}' → '{$backfilledPod}'\n";
    } else {
        echo "❌ Backfill did not modify POD\n";
    }

} catch (\Throwable $e) {
    echo "❌ Debug failed: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

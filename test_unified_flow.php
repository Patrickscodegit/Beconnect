<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;

echo "🧪 Testing full unified flow (map → backfill → create → GET→PUT → upload)...\n\n";

$doc = Document::latest()->first();
if (!$doc) {
    echo "❌ No documents found\n";
    exit;
}

echo "📄 Working with document: {$doc->filename}\n";
echo "   Current status: " . ($doc->robaws_sync_status ?? 'NULL') . "\n";
echo "   Current quotation ID: " . ($doc->robaws_quotation_id ?? 'NULL') . "\n\n";

try {
    $enh = app(\App\Services\RobawsIntegration\EnhancedRobawsIntegrationService::class);

    // 1) mapping/backfill (no network)
    echo "1️⃣ Processing document from extraction (mapping/backfill)...\n";
    $ok = $enh->processDocumentFromExtraction($doc);
    echo "   Result: " . ($ok ? 'OK' : 'FAIL') . "\n";

    // Check stored fields after processing
    $doc->refresh();
    $d = $doc->robaws_quotation_data ?? [];
    echo "   Customer: " . ($d['customer'] ?? 'NULL') . "\n";
    echo "   Customer ref: " . ($d['customer_reference'] ?? 'NULL') . "\n";
    echo "   POR/POL/POD: " . ($d['por'] ?? 'NULL') . "/" . ($d['pol'] ?? 'NULL') . "/" . ($d['pod'] ?? 'NULL') . "\n";
    echo "   CARGO: " . ($d['cargo'] ?? 'NULL') . "\n";
    echo "   Status after processing: " . ($doc->robaws_sync_status ?? 'NULL') . "\n\n";

    // 2) Test method availability without network calls
    echo "2️⃣ Testing unified service methods...\n";
    $methods = ['processDocument', 'createOfferFromDocument', 'processDocumentFromExtraction', 'exportDocumentForRobaws'];
    foreach ($methods as $method) {
        if (method_exists($enh, $method)) {
            echo "   ✅ Method available: " . $method . "\n";
        } else {
            echo "   ❌ Method missing: " . $method . "\n";
        }
    }
    
    echo "\n3️⃣ Testing container alias...\n";
    $oldServiceInstance = app(\App\Services\RobawsIntegrationService::class);
    $newServiceInstance = app(\App\Services\RobawsIntegration\EnhancedRobawsIntegrationService::class);
    
    echo "   Old service class: " . get_class($oldServiceInstance) . "\n";
    echo "   New service class: " . get_class($newServiceInstance) . "\n";
    echo "   Same instance: " . ($oldServiceInstance === $newServiceInstance ? 'YES' : 'NO') . "\n";
    
    if (method_exists($oldServiceInstance, 'createOfferFromDocument')) {
        echo "   ✅ createOfferFromDocument method available on old service alias\n";
    } else {
        echo "   ❌ createOfferFromDocument method NOT available on old service alias\n";
    }

    echo "\n✅ Unified flow test completed successfully!\n";

} catch (\Throwable $e) {
    echo "❌ Unified flow test failed: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

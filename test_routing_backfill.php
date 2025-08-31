<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;

echo "🧪 Testing routing backfill with enhanced customer reference...

";

$doc = Document::latest()->first();
if (!$doc) {
    echo "❌ No documents found
";
    exit;
}

echo "📄 Working with document: {$doc->filename}
";

// Let's enhance the customer reference to include routing information like in our original example
$enhanced_data = $doc->robaws_quotation_data ?? [];
$enhanced_data['customer_reference'] = 'EXP RORO - BRU - JED - BMW Série 7';

echo "   Enhanced customer reference: " . $enhanced_data['customer_reference'] . "

";

// Save the enhanced data
$doc->update(['robaws_quotation_data' => $enhanced_data]);

try {
    $enh = app(\App\Services\RobawsIntegration\EnhancedRobawsIntegrationService::class);

    echo "1️⃣ Processing document with enhanced routing data...
";
    $ok = $enh->processDocumentFromExtraction($doc);
    echo "   Result: " . ($ok ? 'OK' : 'FAIL') . "
";

    // Check stored fields after processing
    $doc->refresh();
    $d = $doc->robaws_quotation_data ?? [];
    echo "   Customer: " . ($d['customer'] ?? 'NULL') . "
";
    echo "   Customer ref: " . ($d['customer_reference'] ?? 'NULL') . "
";
    echo "   POR: " . ($d['por'] ?? 'NULL') . "
";
    echo "   POL: " . ($d['pol'] ?? 'NULL') . "
";
    echo "   POD: " . ($d['pod'] ?? 'NULL') . "
";
    echo "   CARGO: " . ($d['cargo'] ?? 'NULL') . "
";
    echo "   Status after processing: " . ($doc->robaws_sync_status ?? 'NULL') . "
";

    // Check if routing was properly backfilled
    if (!empty($d['por']) && !empty($d['pod'])) {
        echo "
✅ Routing backfill successful!
";
        echo "   Route: " . $d['por'] . " → " . $d['pod'] . "
";
        
        if ($doc->robaws_sync_status === 'ready') {
            echo "   Document is ready for Robaws sync!
";
        }
    } else {
        echo "
❌ Routing backfill failed - fields are still empty
";
    }

} catch (\Throwable $e) {
    echo "❌ Routing test failed: " . $e->getMessage() . "
";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "
";
}

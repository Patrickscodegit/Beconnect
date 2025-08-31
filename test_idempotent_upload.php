<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;

echo "🧪 Testing idempotent upload smoke test...\n\n";

$doc = Document::whereNotNull('robaws_quotation_id')->latest()->first();
if (!$doc) {
    echo "❌ No documents with robaws_quotation_id found\n";
    echo "Looking for any document with quotation data...\n";
    
    $doc = Document::whereNotNull('robaws_quotation_data')->latest()->first();
    if (!$doc) {
        echo "❌ No documents with quotation data found\n";
        exit;
    }
    
    // For testing, let's set a fake quotation ID
    $doc->update(['robaws_quotation_id' => 'TEST_' . uniqid()]);
    echo "✅ Set test quotation ID: {$doc->robaws_quotation_id}\n";
}

echo "📄 Testing with document: {$doc->filename}\n";
echo "   Quotation ID: {$doc->robaws_quotation_id}\n\n";

try {
    $svc = app(\App\Services\MultiDocumentUploadService::class);
    
    echo "1️⃣ First upload attempt...\n";
    $res1 = $svc->uploadDocumentToQuotation($doc);
    
    echo "2️⃣ Second upload attempt (should be skipped)...\n";
    $res2 = $svc->uploadDocumentToQuotation($doc);
    
    echo "📊 Results:\n";
    echo "   First:  Status=" . ($res1['status'] ?? 'NULL') . ", SHA256=" . substr($res1['sha256'] ?? 'NULL', 0, 16) . "...\n";
    echo "   Second: Status=" . ($res2['status'] ?? 'NULL') . ", SHA256=" . substr($res2['sha256'] ?? 'NULL', 0, 16) . "...\n";
    
    if (($res1['status'] ?? '') === 'success' && ($res2['status'] ?? '') === 'skipped') {
        echo "✅ Idempotency working correctly!\n";
    } else {
        echo "❌ Idempotency issue detected\n";
    }

} catch (\Throwable $e) {
    echo "❌ Upload test failed: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

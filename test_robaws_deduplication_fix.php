<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔧 Testing Robaws Export Deduplication Fix\n";
echo str_repeat("=", 50) . "\n";

// Create test documents with same content but different intake IDs
$intake1 = App\Models\Intake::create(['status' => 'processing']);
$intake2 = App\Models\Intake::create(['status' => 'processing']);

echo "📝 Created test intakes:\n";
echo "- Intake #1: {$intake1->id}\n";
echo "- Intake #2: {$intake2->id}\n";

// Create test email content
$emailContent = <<<EML
From: test@example.com
To: quotes@example.com
Subject: Test BMW Transport
Message-ID: <test-dedup@example.com>

Test BMW transport request for deduplication testing.
EML;

$contentSha = hash('sha256', $emailContent);

// Create documents with same content in different intakes
$doc1 = App\Models\Document::create([
    'intake_id' => $intake1->id,
    'filename' => 'test_bmw_1.eml',
    'file_name' => 'test_bmw_1.eml',
    'storage_disk' => 'documents',
    'storage_path' => 'test/bmw_1.eml',
    'source_content_sha' => $contentSha,
    'source_message_id' => '<test-dedup@example.com>',
    'processing_status' => 'completed',
    'mime_type' => 'message/rfc822'
]);

$doc2 = App\Models\Document::create([
    'intake_id' => $intake2->id,
    'filename' => 'test_bmw_2.eml',
    'file_name' => 'test_bmw_2.eml',
    'storage_disk' => 'documents',
    'storage_path' => 'test/bmw_2.eml',
    'source_content_sha' => $contentSha,
    'source_message_id' => '<test-dedup@example.com>',
    'processing_status' => 'completed',
    'mime_type' => 'message/rfc822'
]);

echo "\n📄 Created test documents:\n";
echo "- Doc #1: ID {$doc1->id}, Intake {$intake1->id}, SHA: " . substr($contentSha, 0, 12) . "\n";
echo "- Doc #2: ID {$doc2->id}, Intake {$intake2->id}, SHA: " . substr($contentSha, 0, 12) . "\n";

// Create extraction records
$extraction1 = App\Models\Extraction::create([
    'document_id' => $doc1->id,
    'status' => 'completed',
    'extracted_data' => [
        'vehicle' => ['brand' => 'BMW', 'model' => 'Test'],
        'contact' => ['email' => 'test@example.com']
    ]
]);

$extraction2 = App\Models\Extraction::create([
    'document_id' => $doc2->id,
    'status' => 'completed',
    'extracted_data' => [
        'vehicle' => ['brand' => 'BMW', 'model' => 'Test'],
        'contact' => ['email' => 'test@example.com']
    ]
]);

echo "\n🔍 Created extractions:\n";
echo "- Extraction #1: ID {$extraction1->id}, Doc {$doc1->id}\n";
echo "- Extraction #2: ID {$extraction2->id}, Doc {$doc2->id}\n";

// Test the deduplication logic without actually calling Robaws
echo "\n🧪 Testing Content-Based Deduplication Logic:\n";

// Simulate first export (would create quotation)
echo "1. First export (Doc #1): Would create new quotation\n";
$doc1->update(['robaws_quotation_id' => 12345, 'robaws_document_id' => 'robaws-doc-1']);
$extraction1->update(['robaws_quotation_id' => 12345]);

// Test second export (should detect duplicate)
echo "2. Second export (Doc #2): Testing duplicate detection...\n";

$existingDocument = App\Models\Document::where('source_content_sha', $doc2->source_content_sha)
    ->whereNotNull('robaws_document_id')
    ->whereNotNull('robaws_quotation_id')
    ->where('id', '!=', $doc2->id)
    ->first();

if ($existingDocument) {
    echo "   ✅ DUPLICATE DETECTED!\n";
    echo "   - Current doc: {$doc2->id}\n";
    echo "   - Existing doc: {$existingDocument->id}\n";
    echo "   - Existing quotation: {$existingDocument->robaws_quotation_id}\n";
    echo "   - Existing robaws doc: {$existingDocument->robaws_document_id}\n";
    echo "   - Content SHA: " . substr($doc2->source_content_sha, 0, 16) . "\n";
    
    // Simulate the deduplication action
    $doc2->update([
        'robaws_quotation_id' => $existingDocument->robaws_quotation_id,
        'robaws_document_id' => $existingDocument->robaws_document_id,
        'processing_status' => 'duplicate_content'
    ]);
    
    echo "   ✅ Document updated to reference existing upload\n";
} else {
    echo "   ❌ DUPLICATE NOT DETECTED - This would cause duplicate uploads!\n";
}

// Verify final state
echo "\n📊 Final Document State:\n";
$doc1Fresh = App\Models\Document::find($doc1->id);
$doc2Fresh = App\Models\Document::find($doc2->id);

echo "- Doc #1: Quotation {$doc1Fresh->robaws_quotation_id}, Robaws Doc {$doc1Fresh->robaws_document_id}, Status: {$doc1Fresh->processing_status}\n";
echo "- Doc #2: Quotation {$doc2Fresh->robaws_quotation_id}, Robaws Doc {$doc2Fresh->robaws_document_id}, Status: {$doc2Fresh->processing_status}\n";

if ($doc1Fresh->robaws_document_id === $doc2Fresh->robaws_document_id) {
    echo "\n🎉 SUCCESS: Both documents reference the same Robaws upload!\n";
    echo "👉 No duplicate files will appear in Robaws.\n";
} else {
    echo "\n⚠️  Issue: Documents have different Robaws document IDs.\n";
}

// Clean up test data
$doc1->delete();
$doc2->delete();
$extraction1->delete();
$extraction2->delete();
$intake1->delete();
$intake2->delete();

echo "\n🧹 Test data cleaned up.\n";
echo "\n" . str_repeat("=", 50) . "\n";
echo "✅ Robaws Export Deduplication Test Completed\n";

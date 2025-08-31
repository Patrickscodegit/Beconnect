<?php

require_once __DIR__ . '/bootstrap/app.php';

$app = new \Illuminate\Foundation\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

$app->singleton(
    \Illuminate\Contracts\Http\Kernel::class,
    \App\Http\Kernel::class
);

$app->singleton(
    \Illuminate\Contracts\Console\Kernel::class,
    \App\Console\Kernel::class
);

$app->singleton(
    \Illuminate\Contracts\Debug\ExceptionHandler::class,
    \App\Exceptions\Handler::class
);

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Document;
use App\Models\Intake;
use App\Services\EmailDocumentService;

echo "🧪 Testing Intake-Scoped Deduplication\n";
echo str_repeat("=", 50) . "\n";

// Check current state
echo "\n📊 Current State:\n";
$intake4 = Intake::find(4);
$intake6 = Intake::find(6);

if ($intake4) {
    echo "Intake 4 documents: " . $intake4->documents()->count() . "\n";
    echo "- Processing statuses: " . $intake4->documents()->pluck('processing_status')->unique()->implode(', ') . "\n";
}

if ($intake6) {
    echo "Intake 6 documents: " . $intake6->documents()->count() . "\n";
    echo "- Processing statuses: " . $intake6->documents()->pluck('processing_status')->unique()->implode(', ') . "\n";
}

// Test the EmailDocumentService with intake scoping
echo "\n🔬 Testing EmailDocumentService deduplication:\n";

$service = new EmailDocumentService();

// Get a document from intake 4 to test with
$doc4 = $intake4?->documents()->first();
if ($doc4 && $doc4->file_path) {
    echo "Using document from Intake 4: {$doc4->filename}\n";
    
    // Test duplicate check scoped to intake 4 (should find duplicate)
    $rawEmail = \Storage::disk($doc4->storage_disk)->get($doc4->file_path);
    $duplicateCheck4 = $service->isDuplicate($rawEmail, 4);
    echo "Duplicate check for Intake 4: " . ($duplicateCheck4['is_duplicate'] ? "DUPLICATE" : "NEW") . "\n";
    
    // Test duplicate check scoped to intake 6 (should NOT find duplicate with new scoping)
    $duplicateCheck6 = $service->isDuplicate($rawEmail, 6);
    echo "Duplicate check for Intake 6: " . ($duplicateCheck6['is_duplicate'] ? "DUPLICATE" : "NEW") . "\n";
    
    // Test global duplicate check (should find duplicate)
    $duplicateCheckGlobal = $service->isDuplicate($rawEmail);
    echo "Global duplicate check: " . ($duplicateCheckGlobal['is_duplicate'] ? "DUPLICATE" : "NEW") . "\n";
    
    echo "\n✅ Results:\n";
    echo "- Same email in same intake: " . ($duplicateCheck4['is_duplicate'] ? "✅ Detected as duplicate" : "❌ Should be duplicate") . "\n";
    echo "- Same email in different intake: " . (!$duplicateCheck6['is_duplicate'] ? "✅ Allowed as new" : "❌ Should be allowed") . "\n";
    echo "- Global check still works: " . ($duplicateCheckGlobal['is_duplicate'] ? "✅ Still detects duplicates" : "❌ Should detect") . "\n";
} else {
    echo "❌ No document found in Intake 4 to test with\n";
}

echo "\n🎯 Recommendation:\n";
echo "If the test shows 'Same email in different intake: ✅ Allowed as new',\n";
echo "then Intake 6 should now show the Extract Data button!\n";
echo "\nNext step: Refresh your browser and check Intake 6 for the Extract Data button.\n";

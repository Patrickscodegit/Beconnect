<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🚀 ROBAWS EXPORT SERVICE TEST\n";
echo "============================\n\n";

try {
    // Test service instantiation
    $exportService = app(App\Services\RobawsExportService::class);
    echo "✅ RobawsExportService instantiated successfully\n";

    // Find a document to test
    $document = App\Models\Document::whereHas('extractions', function ($query) {
        $query->where('status', 'completed')
              ->whereNotNull('extracted_data');
    })->first();

    if ($document) {
        echo "📄 Found test document: {$document->file_name} (ID: {$document->id})\n";
        echo "   Already has quotation ID: " . ($document->robaws_quotation_id ?: 'No') . "\n";
        echo "   Already has document ID: " . ($document->robaws_document_id ?: 'No') . "\n\n";

        echo "🔧 Testing document export...\n";
        $result = $exportService->exportDocument($document);

        if ($result['success']) {
            echo "✅ Export successful!\n";
            echo "   Quotation ID: {$result['quotation_id']}\n";
            echo "   Document ID: {$result['document_id']}\n";
            
            if (isset($result['already_exported'])) {
                echo "   Status: Already exported\n";
            } else {
                echo "   Status: Newly exported\n";
            }
        } else {
            echo "❌ Export failed: {$result['error']}\n";
        }
    } else {
        echo "❌ No documents with completed extractions found\n";
    }

    echo "\n🎉 Test completed!\n";

} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

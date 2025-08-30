<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;
use App\Services\RobawsExportService;

echo "🧪 TESTING FIELD MAPPING WITH REAL EXPORT\n";
echo "==========================================\n";

// Use document 3 which we know works
$documentId = 3;
$document = Document::find($documentId);

if (!$document) {
    echo "❌ Document $documentId not found\n";
    exit(1);
}

echo "� Testing with document: {$document->filename}\n";
echo "   Document ID: {$document->id}\n";

// Check extraction data
$extraction = $document->extractions()->latest()->first();
if (!$extraction) {
    echo "❌ No extraction found for document\n";
    exit(1);
}

echo "📊 Extraction Status: {$extraction->status}\n";

// Test the export service using Laravel's service container
$exportService = app(RobawsExportService::class);

try {
    echo "\n🚀 Running export with field mapping...\n";
    
    $result = $exportService->exportDocument($document);
    
    if ($result['success']) {
        echo "✅ Export successful!\n";
        echo "   Quotation ID: {$result['quotation_id']}\n";
        if (isset($result['robaws_document_id'])) {
            echo "   Document ID: {$result['robaws_document_id']}\n";
        }
        if (isset($result['document_id'])) {
            echo "   Document ID: {$result['document_id']}\n";
        }
        echo "\n📋 IMPORTANT: Check Robaws quotation {$result['quotation_id']} to verify:\n";
        echo "   - Customer field is populated\n";
        echo "   - POR (Port of Receipt) field is populated\n";
        echo "   - POL (Port of Loading) field is populated\n";
        echo "   - POD (Port of Discharge) field is populated\n";
        echo "   - Cargo details are populated\n";
        echo "   - JSON field contains the raw data\n";
    } else {
        echo "❌ Export failed: {$result['message']}\n";
    }
    
} catch (Exception $e) {
    echo "❌ Export error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n🎯 Field Mapping Test Complete!\n";
echo "Please check the Robaws interface to verify the field mapping is working.\n";

<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧪 TESTING UPDATED FIELD MAPPING\n";
echo "===============================\n\n";

try {
    // Get the latest document with extraction
    $document = App\Models\Document::whereHas('extractions', function ($query) {
        $query->where('status', 'completed')
              ->whereNotNull('extracted_data');
    })->latest()->first();

    if (!$document) {
        echo "❌ No documents with extractions found\n";
        exit;
    }

    echo "📄 Testing with document ID: {$document->id}\n";
    echo "   File: {$document->file_name}\n\n";

    // Get the extraction data
    $extraction = $document->extractions()->latest()->first();
    $extractedData = is_string($extraction->extracted_data) 
        ? json_decode($extraction->extracted_data, true) 
        : $extraction->extracted_data;

    echo "📊 Extracted Data Structure:\n";
    echo "   Has JSON field: " . (isset($extractedData['JSON']) ? 'YES (' . strlen($extractedData['JSON']) . ' chars)' : 'NO') . "\n";
    echo "   Contact email: " . ($extractedData['extraction_data']['raw_extracted_data']['contact']['email'] ?? 'None') . "\n";
    echo "   Vehicle brand: " . ($extractedData['extraction_data']['raw_extracted_data']['vehicle']['brand'] ?? 'None') . "\n";
    echo "   Origin: " . ($extractedData['extraction_data']['raw_extracted_data']['shipment']['origin'] ?? 'None') . "\n";
    echo "   Destination: " . ($extractedData['extraction_data']['raw_extracted_data']['shipment']['destination'] ?? 'None') . "\n\n";

    // Test the new RobawsExportService
    $exportService = app(App\Services\RobawsExportService::class);
    echo "🚀 Testing export with updated field mapping...\n";
    
    $result = $exportService->exportDocument($document);

    if ($result['success']) {
        echo "✅ Export successful!\n";
        echo "   Quotation ID: {$result['quotation_id']}\n";
        echo "   Document ID: {$result['document_id']}\n";
        
        // Now check what fields were actually mapped
        echo "\n📋 Checking Robaws quotation details...\n";
        
        // You would need to check the Robaws interface to see if the fields are populated
        echo "   Please check the Robaws quotation {$result['quotation_id']} to verify field mapping!\n";
        
    } else {
        echo "❌ Export failed: {$result['error']}\n";
    }

    echo "\n🎯 Field Mapping Test Complete!\n";
    echo "Check the Robaws interface to verify that individual fields are now populated.\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

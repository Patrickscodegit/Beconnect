<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🎯 Final BMW Série 7 Export Test\n";
echo "=================================\n\n";

try {
    $intake = \App\Models\Intake::latest()->first();
    
    if (!$intake) {
        echo "❌ No intake found\n";
        exit(1);
    }
    
    echo "📋 Testing Intake ID: {$intake->id}\n";
    
    // Check if we have documents with extraction data
    $documents = $intake->documents()->whereHas('extractions')->get();
    echo "📄 Documents with extractions: " . $documents->count() . "\n";
    
    if ($documents->isEmpty()) {
        echo "❌ No documents with extractions found\n";
        exit(1);
    }
    
    // Show extraction data structure
    $firstDoc = $documents->first();
    $extraction = $firstDoc->extractions()->first();
    
    if ($extraction && $extraction->extracted_data) {
        $data = $extraction->extracted_data;
        echo "🔍 Extraction data structure:\n";
        echo "- Has document_data: " . (isset($data['document_data']) ? 'YES' : 'NO') . "\n";
        
        if (isset($data['document_data']['vehicle'])) {
            $vehicle = $data['document_data']['vehicle'];
            echo "- Vehicle: " . ($vehicle['brand'] ?? 'N/A') . " " . ($vehicle['model'] ?? 'N/A') . "\n";
        }
        
        if (isset($data['document_data']['shipment'])) {
            $shipment = $data['document_data']['shipment'];
            echo "- Route: " . ($shipment['origin'] ?? 'N/A') . " → " . ($shipment['destination'] ?? 'N/A') . "\n";
        }
    }
    
    echo "\n🚀 Starting export test...\n";
    
    // Test the export (this will use our new root-agnostic mapping)
    $exportService = app(\App\Services\Robaws\RobawsExportService::class);
    $result = $exportService->exportIntake($intake);
    
    echo "\n📊 Export Results:\n";
    echo "- Success: " . count($result['success']) . "\n";
    echo "- Failed: " . count($result['failed']) . "\n";
    echo "- Uploaded: " . count($result['uploaded']) . "\n";
    echo "- Exists: " . count($result['exists']) . "\n";
    echo "- Skipped: " . count($result['skipped']) . "\n";
    
    if (!empty($result['failed'])) {
        echo "\n❌ Failures:\n";
        foreach ($result['failed'] as $failure) {
            echo "- " . ($failure['message'] ?? 'Unknown error') . "\n";
        }
    }
    
    if (!empty($result['success'])) {
        echo "\n✅ Successes:\n";
        foreach ($result['success'] as $success) {
            echo "- " . ($success['message'] ?? 'Success') . "\n";
        }
    }
    
    // Check if Robaws offer was created/updated
    $intake->refresh();
    if ($intake->robaws_offer_id) {
        echo "\n🎯 Robaws Offer ID: {$intake->robaws_offer_id}\n";
        echo "✅ BMW Série 7 export mapping SUCCESSFUL!\n";
    } else {
        echo "\n⚠️  No Robaws offer ID found (might be connection issue)\n";
    }
    
} catch (Throwable $e) {
    echo "❌ Test failed: " . $e->getMessage() . "\n";
    echo "📁 File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    
    if (str_contains($e->getMessage(), 'document_data') || str_contains($e->getMessage(), 'vehicle')) {
        echo "\n🔧 This suggests the mapping fix is needed!\n";
    }
}

echo "\n🎉 Test Complete!\n";

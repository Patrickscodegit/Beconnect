<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔧 AUTOMATIC QUOTATION ID FIXER\n";
echo "===============================\n\n";

// Find and fix documents with completed processing but missing quotation IDs
$documentsToFix = \App\Models\Document::where('processing_status', 'completed')
    ->whereNull('robaws_quotation_id')
    ->with('extractions')
    ->get();

echo "Found {$documentsToFix->count()} completed documents missing quotation IDs\n\n";

foreach ($documentsToFix as $document) {
    echo "Processing document {$document->id} ({$document->filename}):\n";
    
    // Check if extraction has quotation ID
    $extraction = $document->extractions()->whereNotNull('robaws_quotation_id')->first();
    
    if ($extraction) {
        echo "  → Found extraction with quotation ID: {$extraction->robaws_quotation_id}\n";
        $document->update(['robaws_quotation_id' => $extraction->robaws_quotation_id]);
        echo "  ✅ Updated document quotation ID\n";
    } else {
        // Try to reprocess through integration
        echo "  → No quotation ID found, attempting reprocessing...\n";
        
        try {
            $integrationService = app(\App\Services\RobawsIntegration\EnhancedRobawsIntegrationService::class);
            
            // Get extraction data
            $latestExtraction = $document->extractions()->latest()->first();
            if ($latestExtraction && $latestExtraction->extracted_data) {
                $extractedData = is_string($latestExtraction->extracted_data) 
                    ? json_decode($latestExtraction->extracted_data, true)
                    : $latestExtraction->extracted_data;
                
                if ($extractedData) {
                    $result = $integrationService->processDocument($document, $extractedData);
                    echo "  " . ($result ? "✅ Reprocessing successful" : "❌ Reprocessing failed") . "\n";
                } else {
                    echo "  ❌ No extraction data available\n";
                }
            } else {
                echo "  ❌ No extraction found for reprocessing\n";
            }
        } catch (\Exception $e) {
            echo "  ❌ Reprocessing error: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n";
}

if ($documentsToFix->count() === 0) {
    echo "✅ All completed documents have proper quotation IDs!\n";
}

echo "🎯 MAINTENANCE COMPLETE\n";
echo "Run this script periodically to catch any edge cases.\n";

<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔧 FIXING EXTRACTION QUOTATION IDs\n";
echo "===================================\n\n";

// Find documents that have robaws_quotation_id but their extractions don't
$documentsToFix = \App\Models\Document::whereNotNull('robaws_quotation_id')
    ->whereHas('extractions', function($query) {
        $query->whereNull('robaws_quotation_id');
    })
    ->with('extractions')
    ->get();

echo "Found {$documentsToFix->count()} documents with missing extraction quotation IDs\n\n";

foreach ($documentsToFix as $document) {
    echo "Fixing document {$document->id} (Robaws ID: {$document->robaws_quotation_id}):\n";
    
    $extractionsUpdated = 0;
    foreach ($document->extractions as $extraction) {
        if (!$extraction->robaws_quotation_id) {
            $extraction->update(['robaws_quotation_id' => $document->robaws_quotation_id]);
            $extractionsUpdated++;
            echo "  ✓ Updated extraction {$extraction->id}\n";
        }
    }
    
    echo "  → Updated {$extractionsUpdated} extractions\n\n";
}

echo "✅ All extractions now have matching quotation IDs\n";
echo "This ensures consistency between documents and extractions.\n";

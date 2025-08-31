<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 CHECKING ALL RECENT QUOTATIONS FOR EMPTY DOCUMENTS\n";
echo "====================================================\n\n";

// Get all quotations from the last few days
$quotations = \App\Models\Quotation::where('created_at', '>=', now()->subDays(2))
    ->orderBy('created_at', 'desc')
    ->get();

echo "Found {$quotations->count()} quotations from last 2 days:\n\n";

$needsFix = [];
$needsUpload = [];
$noDocument = [];

foreach ($quotations as $q) {
    echo "Quotation {$q->id} (Robaws ID: {$q->robaws_id})\n";
    echo "  Created: {$q->created_at}\n";
    echo "  Document ID: " . ($q->document_id ?: 'None') . "\n";
    
    if ($q->document_id) {
        $doc = \App\Models\Document::find($q->document_id);
        if ($doc) {
            echo "  Document filename: {$doc->filename}\n";
            echo "  Robaws quotation ID: " . ($doc->robaws_quotation_id ?: 'MISSING!') . "\n";
            echo "  Upload status: " . ($doc->upload_status ?: 'None') . "\n";
            
            if (!$doc->robaws_quotation_id) {
                echo "  ❌ NEEDS FIX: Missing robaws_quotation_id!\n";
                $needsFix[] = ['quotation' => $q, 'document' => $doc];
            } elseif (!$doc->upload_status) {
                echo "  ❌ NEEDS UPLOAD: Has quotation ID but no upload status!\n";
                $needsUpload[] = ['quotation' => $q, 'document' => $doc];
            } else {
                echo "  ✅ LOOKS GOOD: Has quotation ID and upload status\n";
            }
        }
    } else {
        echo "  ❌ NO DOCUMENT: Quotation has no document_id\n";
        $noDocument[] = $q;
    }
    echo "\n";
}

echo str_repeat("=", 60) . "\n\n";

// Fix missing robaws_quotation_id links
if (!empty($needsFix)) {
    echo "🔧 FIXING MISSING ROBAWS_QUOTATION_ID LINKS:\n";
    echo "===========================================\n";
    
    foreach ($needsFix as $item) {
        $quotation = $item['quotation'];
        $document = $item['document'];
        
        echo "Fixing Document {$document->id} -> Quotation {$quotation->robaws_id}\n";
        $document->update(['robaws_quotation_id' => $quotation->robaws_id]);
        echo "✅ Updated!\n";
    }
    echo "\n";
}

// Trigger uploads for documents that need it
if (!empty($needsUpload)) {
    echo "🚀 TRIGGERING UPLOADS FOR PENDING DOCUMENTS:\n";
    echo "==========================================\n";
    
    foreach ($needsUpload as $item) {
        $quotation = $item['quotation'];
        $document = $item['document'];
        
        echo "Triggering upload for Document {$document->id}\n";
        // The DocumentObserver should trigger automatically when we touch the robaws_quotation_id
        $document->touch();
        echo "✅ Triggered!\n";
    }
    echo "\n";
}

// Summary
$totalProblems = count($needsFix) + count($needsUpload) + count($noDocument);
echo "📊 SUMMARY:\n";
echo "===========\n";
echo "Fixed missing links: " . count($needsFix) . "\n";
echo "Triggered uploads: " . count($needsUpload) . "\n";
echo "Quotations without documents: " . count($noDocument) . "\n";
echo "Total problems found: {$totalProblems}\n";

if ($totalProblems === 0) {
    echo "\n🎉 All quotations look good!\n";
} else {
    echo "\n⏳ Processing uploads... check again in a few moments.\n";
}

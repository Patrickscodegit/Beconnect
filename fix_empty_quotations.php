<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 INVESTIGATING EMPTY QUOTATIONS\n";
echo "=================================\n\n";

// Check quotation 11445
$quotation = \App\Models\Quotation::where('robaws_id', '11445')->first();

if ($quotation) {
    echo "Found quotation {$quotation->id} with Robaws ID 11445\n";
    echo "Document ID: " . ($quotation->document_id ?: 'None') . "\n";
    echo "User ID: " . ($quotation->user_id ?: 'None') . "\n";
    echo "Created: {$quotation->created_at}\n";
    
    if ($quotation->document_id) {
        $document = \App\Models\Document::find($quotation->document_id);
        if ($document) {
            echo "\nAssociated document:\n";
            echo "  Filename: {$document->filename}\n";
            echo "  Robaws quotation ID: " . ($document->robaws_quotation_id ?: 'None') . "\n";
            echo "  Upload status: " . ($document->upload_status ?: 'None') . "\n";
            echo "  Processing status: " . ($document->processing_status ?: 'None') . "\n";
            
            if (!$document->robaws_quotation_id) {
                echo "\n❌ PROBLEM: Document does not have robaws_quotation_id set!\n";
                echo "This means the DocumentObserver upload trigger will not work.\n";
                
                echo "\n🔧 FIXING: Setting robaws_quotation_id...\n";
                $document->update(['robaws_quotation_id' => '11445']);
                echo "✅ Updated document with robaws_quotation_id = 11445\n";
                
                echo "\n⏳ Upload should be triggered automatically now...\n";
                sleep(2);
                
                // Check if upload happened
                $document->refresh();
                echo "New upload status: " . ($document->upload_status ?: 'Still none') . "\n";
            }
        } else {
            echo "Document {$quotation->document_id} not found!\n";
        }
    } else {
        echo "\n❌ This quotation has no associated document_id\n";
    }
} else {
    echo "Quotation with Robaws ID 11445 not found in our database!\n";
}

echo "\n" . str_repeat("=", 50) . "\n\n";

// Also check quotation 11444
echo "Checking quotation 11444...\n";
$quotation2 = \App\Models\Quotation::where('robaws_id', '11444')->first();

if ($quotation2) {
    echo "Found quotation {$quotation2->id} with Robaws ID 11444\n";
    echo "Document ID: " . ($quotation2->document_id ?: 'None') . "\n";
    
    if ($quotation2->document_id) {
        $document2 = \App\Models\Document::find($quotation2->document_id);
        if ($document2) {
            echo "Document {$document2->id}: {$document2->filename}\n";
            echo "Robaws quotation ID: " . ($document2->robaws_quotation_id ?: 'None') . "\n";
            
            if (!$document2->robaws_quotation_id) {
                echo "🔧 Fixing document {$document2->id}...\n";
                $document2->update(['robaws_quotation_id' => '11444']);
                echo "✅ Updated document with robaws_quotation_id = 11444\n";
            }
        }
    }
} else {
    echo "Quotation 11444 not found\n";
}

echo "\n🎯 Summary: Fixed missing robaws_quotation_id links to trigger uploads\n";

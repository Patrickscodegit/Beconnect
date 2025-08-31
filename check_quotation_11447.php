<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 INVESTIGATING NEW QUOTATION 11447\n";
echo "====================================\n\n";

// Find the quotation that was just created
$quotation = \App\Models\Quotation::where('robaws_id', '11447')->first();

if ($quotation) {
    echo "Found quotation {$quotation->id} with Robaws ID 11447\n";
    echo "Document ID: " . ($quotation->document_id ?: 'None') . "\n";
    echo "Created: {$quotation->created_at}\n";
    
    if ($quotation->document_id) {
        $document = \App\Models\Document::find($quotation->document_id);
        if ($document) {
            echo "\nAssociated document:\n";
            echo "  ID: {$document->id}\n";
            echo "  Filename: {$document->filename}\n";
            echo "  Robaws quotation ID: " . ($document->robaws_quotation_id ?: 'MISSING!') . "\n";
            echo "  Upload status: " . ($document->upload_status ?: 'None') . "\n";
            echo "  Processing status: " . ($document->processing_status ?: 'None') . "\n";
            echo "  File path: {$document->file_path}\n";
            echo "  Storage disk: " . ($document->storage_disk ?: 'None') . "\n";
            
            // Check if file exists
            $filePath = storage_path('app/' . $document->file_path);
            echo "  File exists (direct): " . (file_exists($filePath) ? 'Yes' : 'No') . "\n";
            
            // Try different paths
            $possiblePaths = [
                storage_path('app/private/documents/' . $document->filename),
                storage_path('app/private/' . $document->file_path),
                $document->file_path // If it's already absolute
            ];
            
            foreach ($possiblePaths as $path) {
                if (file_exists($path)) {
                    echo "  Found file at: {$path}\n";
                    break;
                }
            }
            
            if (!$document->robaws_quotation_id) {
                echo "\n🔧 FIXING: Setting robaws_quotation_id...\n";
                $document->update(['robaws_quotation_id' => '11447']);
                echo "✅ Updated document with robaws_quotation_id = 11447\n";
            } elseif (!$document->upload_status) {
                echo "\n🔧 TRIGGERING UPLOAD: Document has quotation ID but no upload status...\n";
                // Trigger upload by updating the document
                $document->touch();
                echo "✅ Triggered upload for document {$document->id}\n";
            }
        } else {
            echo "Document {$quotation->document_id} not found!\n";
        }
    } else {
        echo "\n❌ This quotation has no associated document_id\n";
    }
} else {
    echo "Quotation with Robaws ID 11447 not found in our database!\n";
    
    // Look for most recent quotations
    echo "\nMost recent quotations:\n";
    $recent = \App\Models\Quotation::latest()->take(5)->get();
    foreach ($recent as $q) {
        echo "  Quotation {$q->id}: Robaws ID {$q->robaws_id}, Created: {$q->created_at}\n";
    }
}

<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🚀 TRIGGERING UPLOAD FOR QUOTATION 11447\n";
echo "=========================================\n\n";

$document = \App\Models\Document::find(61);

if ($document) {
    echo "Document found: {$document->filename}\n";
    echo "Robaws quotation ID: {$document->robaws_quotation_id}\n";
    
    // The file path shows as documents/xxx but it's actually in private/documents/
    $actualPath = 'private/documents/' . $document->filename;
    echo "Updating file path from '{$document->file_path}' to '{$actualPath}'\n";
    
    $document->update([
        'file_path' => $actualPath,
        'upload_status' => null, // Reset to trigger upload
        'processing_status' => 'pending'
    ]);
    
    echo "\n🔧 Manually triggering upload via MultiDocumentUploadService...\n";
    
    $uploadService = app(\App\Services\MultiDocumentUploadService::class);
    
    try {
        $result = $uploadService->uploadDocumentToQuotation($document);
        echo "✅ Upload result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
        
        // Check final status
        $document->refresh();
        echo "Final upload status: " . ($document->upload_status ?: 'None') . "\n";
        
    } catch (\Exception $e) {
        echo "❌ Upload failed: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
} else {
    echo "Document 61 not found!\n";
}

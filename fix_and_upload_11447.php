<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔧 FIXING FILE PATH FOR DOCUMENT 61\n";
echo "====================================\n\n";

$document = \App\Models\Document::find(61);

if ($document) {
    echo "Current file path: {$document->file_path}\n";
    
    // The actual file path
    $correctPath = 'private/documents/68b317dc2b9b6_01K3XSAD01D9MCYWVBNHSTM8P9.pdf';
    
    echo "Updating to correct path: {$correctPath}\n";
    
    $document->update([
        'file_path' => $correctPath,
        'upload_status' => null // Reset to trigger upload
    ]);
    
    echo "✅ File path updated\n";
    
    // Verify file exists
    $fullPath = storage_path('app/' . $correctPath);
    echo "File exists: " . (file_exists($fullPath) ? 'Yes' : 'No') . "\n";
    
    if (file_exists($fullPath)) {
        echo "File size: " . filesize($fullPath) . " bytes\n";
        
        echo "\n🚀 Now triggering upload...\n";
        
        $uploadService = app(\App\Services\MultiDocumentUploadService::class);
        
        try {
            $result = $uploadService->uploadDocumentToQuotation($document);
            echo "✅ Upload result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
            
            // Check final status
            $document->refresh();
            echo "\nFinal status:\n";
            echo "  Upload status: " . ($document->upload_status ?: 'None') . "\n";
            echo "  Processing status: " . ($document->processing_status ?: 'None') . "\n";
            
        } catch (\Exception $e) {
            echo "❌ Upload failed: " . $e->getMessage() . "\n";
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        }
    }
} else {
    echo "Document 61 not found!\n";
}

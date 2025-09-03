<?php

echo "=== TESTING .EML FILE PROCESSING & UPLOAD ===\n\n";

// Test with EmailDocumentService 
$emlFile = 'real_bmw_serie7.eml';
echo "Testing with: $emlFile\n";

if (!file_exists($emlFile)) {
    echo "❌ EML file not found: $emlFile\n";
    exit;
}

$fileSize = filesize($emlFile);
echo "✅ EML file exists: " . number_format($fileSize) . " bytes\n\n";

try {
    // Process email using EmailDocumentService
    echo "🔄 Processing email with EmailDocumentService...\n";
    
    // First, create an intake
    $intakeService = app(App\Services\IntakeCreationService::class);
    $emailContent = file_get_contents($emlFile);
    
    $intake = $intakeService->createFromEmail($emailContent, [
        'source' => 'email_test',
        'notes' => 'Testing .eml file upload'
    ]);
    
    echo "✅ Intake created: ID " . $intake->id . "\n";
    echo "Status: " . $intake->status . "\n";
    
    // Check files created
    $files = $intake->files;
    echo "Files count: " . $files->count() . "\n";
    
    foreach ($files as $file) {
        echo "\n📄 File: " . $file->filename . "\n";
        echo "Storage path: " . $file->storage_path . "\n";
        echo "File size: " . number_format($file->file_size) . " bytes\n";
        echo "Mime type: " . $file->mime_type . "\n";
        
        // Check if file exists on storage
        $exists = Storage::disk($file->storage_disk)->exists($file->storage_path);
        echo "File exists on disk: " . ($exists ? 'YES' : 'NO') . "\n";
    }
    
    // Wait a moment for processing, then check status
    echo "\n⏱️ Waiting 2 seconds for processing...\n";
    sleep(2);
    
    $intake->refresh();
    echo "Updated status: " . $intake->status . "\n";
    echo "Robaws client ID: " . ($intake->robaws_client_id ?? 'none') . "\n";
    
    // Check for documents (after extraction)
    $documents = $intake->documents;
    echo "Documents count: " . $documents->count() . "\n";
    
    foreach ($documents as $doc) {
        echo "\n📎 Document: " . ($doc->original_filename ?? $doc->file_name ?? 'unknown') . "\n";
        echo "Robaws quotation ID: " . ($doc->robaws_quotation_id ?? 'none') . "\n";
        echo "Upload status: " . ($doc->upload_status ?? 'none') . "\n";
        echo "File path: " . ($doc->file_path ?? $doc->filepath ?? 'missing') . "\n";
        
        // Check if document file exists
        if ($doc->file_path) {
            $exists = Storage::disk('documents')->exists($doc->file_path);
            echo "Document file exists: " . ($exists ? 'YES' : 'NO') . "\n";
            
            // Test upload if we have a quotation ID
            if ($doc->robaws_quotation_id && $exists) {
                echo "🚀 Testing upload to Robaws offer " . $doc->robaws_quotation_id . "...\n";
                
                $exportService = app(App\Services\Robaws\RobawsExportService::class);
                $uploadResult = $exportService->uploadDocumentToOffer(
                    $doc->robaws_quotation_id, 
                    $doc->file_path
                );
                
                echo "Upload status: " . ($uploadResult['status'] ?? 'unknown') . "\n";
                if (isset($uploadResult['document']['id'])) {
                    echo "Robaws document ID: " . $uploadResult['document']['id'] . "\n";
                }
                if (isset($uploadResult['error'])) {
                    echo "Upload error: " . $uploadResult['error'] . "\n";
                }
            }
        }
    }
    
} catch (\Exception $e) {
    echo "❌ Processing failed: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

<?php

echo "=== COMPLETE .EML FILE UPLOAD WORKFLOW TEST ===\n\n";

// Test complete workflow with queue processing
$emlFile = 'test-vehicle-transport.eml';

if (!file_exists($emlFile)) {
    echo "❌ Test .eml file not found: $emlFile\n";
    exit;
}

echo "Testing complete workflow with: $emlFile\n";
echo "File size: " . number_format(filesize($emlFile)) . " bytes\n\n";

// 1. Create intake from .eml
echo "1️⃣ Creating intake from .eml file...\n";
$intakeService = app(App\Services\IntakeCreationService::class);
$emailContent = file_get_contents($emlFile);

$intake = $intakeService->createFromEmail($emailContent, [
    'source' => 'complete_test',
    'notes' => 'Complete .eml workflow test'
]);

echo "✅ Intake created: ID " . $intake->id . "\n";
echo "Status: " . $intake->status . "\n";

// 2. Process intake (extraction)
echo "\n2️⃣ Processing intake for data extraction...\n";
$job = new App\Jobs\ProcessIntake($intake);
$job->handle();

$intake->refresh();
echo "✅ Processing complete\n";
echo "Status: " . $intake->status . "\n";
echo "Customer: " . ($intake->customer_name ?? 'none') . "\n";
echo "Email: " . ($intake->contact_email ?? 'none') . "\n";
echo "Client ID: " . ($intake->robaws_client_id ?? 'none') . "\n";

// 3. Export to Robaws (if processed)
if ($intake->status === 'processed' && $intake->robaws_client_id) {
    echo "\n3️⃣ Exporting to Robaws...\n";
    $exportService = app(App\Services\Robaws\RobawsExportService::class);
    $result = $exportService->exportIntake($intake);
    
    echo "Export success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
    
    if ($result['success'] && isset($result['quotation_id'])) {
        $quotationId = $result['quotation_id'];
        echo "✅ Robaws offer created: " . $quotationId . "\n";
        
        // 4. Upload .eml file to offer
        echo "\n4️⃣ Uploading .eml file to Robaws offer...\n";
        
        $files = $intake->files;
        foreach ($files as $file) {
            if (str_ends_with($file->filename, '.eml')) {
                echo "Uploading: " . $file->filename . "\n";
                
                $fullPath = Storage::disk($file->storage_disk)->path($file->storage_path);
                $uploadResult = $exportService->uploadDocumentToOffer($quotationId, $fullPath);
                
                echo "Upload status: " . ($uploadResult['status'] ?? 'unknown') . "\n";
                
                if ($uploadResult['status'] === 'uploaded' && isset($uploadResult['document']['id'])) {
                    echo "✅ Document uploaded successfully!\n";
                    echo "Robaws document ID: " . $uploadResult['document']['id'] . "\n";
                    echo "SHA256: " . ($uploadResult['document']['sha256'] ?? 'none') . "\n";
                    
                    echo "\n🎉 COMPLETE WORKFLOW SUCCESS!\n";
                    echo "✅ .eml file processed and uploaded to Robaws\n";
                    echo "✅ Offer: " . $quotationId . "\n";
                    echo "✅ Document: " . $uploadResult['document']['id'] . "\n";
                } else {
                    echo "❌ Upload failed: " . ($uploadResult['error'] ?? 'unknown error') . "\n";
                }
            }
        }
    } else {
        echo "❌ Export failed: " . ($result['error'] ?? 'unknown error') . "\n";
    }
} else {
    echo "\n❌ Intake not ready for export\n";
    echo "Status: " . $intake->status . "\n";
    echo "Client ID: " . ($intake->robaws_client_id ?? 'none') . "\n";
}

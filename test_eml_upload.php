<?php

echo "=== TESTING .EML FILE UPLOAD PROCESS ===\n\n";

// Test processing a BMW .eml file
$emlFile = 'real_bmw_serie7.eml';
echo "Testing with: $emlFile\n";

if (!file_exists($emlFile)) {
    echo "❌ EML file not found: $emlFile\n";
    exit;
}

echo "✅ EML file exists: " . filesize($emlFile) . " bytes\n\n";

// Simulate email processing
$processor = app(App\Services\Email\EmailProcessor::class);
try {
    echo "🔄 Processing email...\n";
    $result = $processor->processEmailFile($emlFile);
    
    echo "Processing result:\n";
    echo "Success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
    if (isset($result['intake_id'])) {
        echo "Intake ID: " . $result['intake_id'] . "\n";
    }
    if (isset($result['error'])) {
        echo "Error: " . $result['error'] . "\n";
    }
    
    // Check if intake was created and has documents
    if (isset($result['intake_id'])) {
        $intake = App\Models\Intake::with('documents')->find($result['intake_id']);
        if ($intake) {
            echo "\n📄 Intake documents:\n";
            foreach ($intake->documents as $doc) {
                echo "- Document ID: " . $doc->id . "\n";
                echo "  Filename: " . ($doc->original_filename ?? $doc->file_name ?? 'unknown') . "\n";
                echo "  File path: " . ($doc->file_path ?? $doc->filepath ?? 'missing') . "\n";
                echo "  Robaws offer ID: " . ($doc->robaws_quotation_id ?? 'none') . "\n";
                echo "  Upload status: " . ($doc->upload_status ?? 'none') . "\n";
                
                // Check if original .eml file exists on disk
                if ($doc->file_path) {
                    $exists = Storage::disk('documents')->exists($doc->file_path);
                    echo "  File exists on disk: " . ($exists ? 'YES' : 'NO') . "\n";
                    
                    if ($exists) {
                        $size = Storage::disk('documents')->size($doc->file_path);
                        echo "  File size: " . number_format($size) . " bytes\n";
                        
                        // Test upload to Robaws if we have an offer
                        if ($doc->robaws_quotation_id) {
                            echo "  🚀 Testing upload to Robaws...\n";
                            $exportService = app(App\Services\Robaws\RobawsExportService::class);
                            $uploadResult = $exportService->uploadDocumentToOffer(
                                $doc->robaws_quotation_id, 
                                $doc->file_path
                            );
                            
                            echo "  Upload status: " . ($uploadResult['status'] ?? 'unknown') . "\n";
                            if (isset($uploadResult['document']['id'])) {
                                echo "  Robaws document ID: " . $uploadResult['document']['id'] . "\n";
                            }
                            if (isset($uploadResult['error'])) {
                                echo "  Upload error: " . $uploadResult['error'] . "\n";
                            }
                        }
                    }
                }
                echo "\n";
            }
        }
    }
    
} catch (\Exception $e) {
    echo "❌ Processing failed: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

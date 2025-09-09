<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

use App\Models\Intake;
use App\Jobs\ProcessIntake;
use Illuminate\Support\Facades\Log;

echo "🔍 Diagnosing Intake Issues\n";
echo "===========================\n\n";

// Check intake 17 (email with needs_contact)
echo "📧 Email Intake (ID: 17)\n";
echo "------------------------\n";

$emailIntake = Intake::with(['files', 'documents'])->find(17);
if ($emailIntake) {
    echo "Status: {$emailIntake->status}\n";
    echo "Customer: {$emailIntake->customer_name}\n";
    echo "Email: {$emailIntake->contact_email}\n";
    echo "Phone: {$emailIntake->contact_phone}\n";
    echo "Files: " . $emailIntake->files->count() . "\n";
    echo "Documents: " . $emailIntake->documents->count() . "\n";
    
    // Check if processing was attempted
    echo "\nFiles details:\n";
    foreach ($emailIntake->files as $file) {
        echo "  - {$file->filename} ({$file->mime_type})\n";
    }
    
    if ($emailIntake->documents->count() === 0) {
        echo "\n❌ Issue: No documents created - ProcessIntake may not have run\n";
        echo "Retrying ProcessIntake job...\n";
        
        ProcessIntake::dispatch($emailIntake);
        echo "✅ ProcessIntake job dispatched\n";
    }
} else {
    echo "❌ Intake 17 not found\n";
}

echo "\n" . str_repeat('=', 50) . "\n\n";

// Check intake 15 & 16 (images completed but no offers)
foreach ([15, 16] as $intakeId) {
    echo "🖼️  Image Intake (ID: {$intakeId})\n";
    echo "------------------------\n";
    
    $imageIntake = Intake::with(['files', 'documents'])->find($intakeId);
    if ($imageIntake) {
        echo "Status: {$imageIntake->status}\n";
        echo "Robaws Client ID: {$imageIntake->robaws_client_id}\n";
        echo "Files: " . $imageIntake->files->count() . "\n";
        echo "Documents: " . $imageIntake->documents->count() . "\n";
        
        if ($imageIntake->documents->count() > 0) {
            foreach ($imageIntake->documents as $doc) {
                echo "  Document {$doc->id}: Status={$doc->processing_status}, Quotation={$doc->robaws_quotation_id}\n";
            }
            
            // Check if we have a client but no quotation
            if ($imageIntake->robaws_client_id && !$imageIntake->documents->whereNotNull('robaws_quotation_id')->count()) {
                echo "❌ Issue: Client created but no quotation - Export may have failed\n";
                echo "Retrying export...\n";
                
                \App\Jobs\ExportIntakeToRobawsJob::dispatch($imageIntake);
                echo "✅ ExportIntakeToRobawsJob dispatched\n";
            }
        }
    } else {
        echo "❌ Intake {$intakeId} not found\n";
    }
    
    echo "\n" . str_repeat('-', 30) . "\n\n";
}

echo "Diagnosis completed.\n";

<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Intake;
use App\Models\IntakeFile;
use App\Jobs\ProcessIntake;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

echo "=== Testing BMW Email Upload & Processing ===\n\n";

try {
    // Create an uploaded file from the BMW email
    $emailPath = __DIR__ . '/test_bmw_email.eml';
    $uploadedFile = new UploadedFile(
        $emailPath,
        'bmw_email.eml',
        'message/rfc822',
        null,
        true // test mode
    );

    echo "✅ Created UploadedFile: " . $uploadedFile->getClientOriginalName() . "\n";
    echo "   Size: " . $uploadedFile->getSize() . " bytes\n";
    echo "   MIME: " . $uploadedFile->getMimeType() . "\n\n";

    // Create an intake
    $intake = Intake::create([
        'reference' => 'TEST-BMW-' . time(),
        'status' => 'queued',
        'customer_name' => null, // Let extraction fill this
        'contact_email' => null, // Let extraction fill this
        'contact_phone' => null,
        'extraction_data' => [],
        'export_attempt_count' => 0,
    ]);

    echo "📋 Created intake ID: {$intake->id}\n";
    echo "   Reference: {$intake->reference}\n";
    echo "   Initial status: {$intake->status}\n\n";

    // Store the file
    $path = $uploadedFile->store('documents', 'local');
    echo "💾 Stored file at: {$path}\n\n";

    // Create IntakeFile record (not Document)
    $intakeFile = IntakeFile::create([
        'intake_id' => $intake->id,
        'filename' => $uploadedFile->getClientOriginalName(),
        'mime_type' => $uploadedFile->getMimeType(),
        'file_size' => $uploadedFile->getSize(),
        'storage_path' => $path,
        'storage_disk' => 'local',
    ]);

    echo "📄 Created IntakeFile ID: {$intakeFile->id}\n";
    echo "   Filename: {$intakeFile->filename}\n";
    echo "   MIME type: {$intakeFile->mime_type}\n";
    echo "   Storage: {$intakeFile->storage_disk}\n\n";

    // Process the intake
    echo "🔄 Processing intake...\n";
    $job = new ProcessIntake($intake);
    $job->handle();

    // Refresh intake to see the results
    $intake->refresh();
    
    echo "\n📊 Results:\n";
    echo "   Status: {$intake->status}\n";
    echo "   Customer Name: " . ($intake->customer_name ?? 'NULL') . "\n";
    echo "   Contact Email: " . ($intake->contact_email ?? 'NULL') . "\n";
    echo "   Contact Phone: " . ($intake->contact_phone ?? 'NULL') . "\n";
    echo "   Export Error: " . ($intake->last_export_error ?? 'NULL') . "\n";
    
    $extractionData = $intake->extraction_data;
    if (!empty($extractionData['contact'])) {
        echo "\n🎯 Extracted Contact Data:\n";
        echo "   Name: " . ($extractionData['contact']['name'] ?? 'NULL') . "\n";
        echo "   Email: " . ($extractionData['contact']['email'] ?? 'NULL') . "\n";
        echo "   Phone: " . ($extractionData['contact']['phone'] ?? 'NULL') . "\n";
    }
    
    if (!empty($extractionData['vehicle'])) {
        echo "\n🚗 Extracted Vehicle Data:\n";
        echo "   Make: " . ($extractionData['vehicle']['make'] ?? 'NULL') . "\n";
        echo "   Model: " . ($extractionData['vehicle']['model'] ?? 'NULL') . "\n";
    }
    
    if (!empty($extractionData['shipment'])) {
        echo "\n🚢 Extracted Shipment Data:\n";
        echo "   Origin: " . ($extractionData['shipment']['origin'] ?? 'NULL') . "\n";
        echo "   Destination: " . ($extractionData['shipment']['destination'] ?? 'NULL') . "\n";
        echo "   Service: " . ($extractionData['shipment']['service'] ?? 'NULL') . "\n";
    }

    // Test the fix success
    if ($intake->status === 'processed' && 
        $intake->contact_email === 'badr.algothami@gmail.com' &&
        $intake->customer_name === 'Badr Algothami') {
        echo "\n🎉 SUCCESS! The fix is working perfectly!\n";
        echo "   ✅ Status: processed (not needs_contact)\n";
        echo "   ✅ Email: badr.algothami@gmail.com\n";
        echo "   ✅ Name: Badr Algothami\n";
    } else {
        echo "\n❌ Issue detected:\n";
        echo "   Expected status: processed, got: {$intake->status}\n";
        echo "   Expected email: badr.algothami@gmail.com, got: " . ($intake->contact_email ?? 'NULL') . "\n";
        echo "   Expected name: Badr Algothami, got: " . ($intake->customer_name ?? 'NULL') . "\n";
    }

} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Test Complete ===\n";

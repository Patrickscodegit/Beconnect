<?php
// Test script to verify image export fix

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🖼️  Testing Image Export Fix\n";
echo "============================\n\n";

// Find an intake with export_failed status
$failedIntake = \App\Models\Intake::where('status', 'export_failed')
    ->whereHas('files', function($q) {
        $q->where('mime_type', 'like', 'image/%');
    })
    ->first();

if ($failedIntake) {
    echo "Found failed image intake: {$failedIntake->id}\n";
    echo "Current status: {$failedIntake->status}\n";
    echo "Last error: {$failedIntake->last_export_error}\n";
    echo "Files:\n";
    
    foreach ($failedIntake->files as $file) {
        echo "  - {$file->filename} ({$file->mime_type})\n";
    }
    
    echo "\nRetrying export...\n";
    
    // Dispatch the export job again
    \App\Jobs\ExportIntakeToRobawsJob::dispatch($failedIntake->id);
    
    echo "✅ Export job dispatched for intake {$failedIntake->id}\n";
    echo "Check the intake status in a few moments.\n";
    
} else {
    echo "No failed image intakes found.\n";
    
    // Create a test intake with a simple image
    echo "Creating test intake with image...\n";
    
    $service = app(\App\Services\IntakeCreationService::class);
    
    // Create a simple test image
    $imageData = base64_encode('test-image-data');
    $base64Image = "data:image/jpeg;base64,$imageData";
    
    $intake = $service->createFromBase64Image($base64Image, 'test_export_fix.jpg', [
        'source' => 'test_export_fix',
        'priority' => 'normal'
    ]);
    
    echo "✅ Created test intake: {$intake->id}\n";
    echo "Status: {$intake->status}\n";
    echo "Check this intake in the admin panel.\n";
}

echo "\nTest completed.\n";

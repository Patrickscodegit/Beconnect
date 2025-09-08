<?php

use App\Models\Intake;
use App\Jobs\ProcessIntake;
use Illuminate\Support\Facades\Log;

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing contact creation in ProcessIntake job...\n";

try {
    // Create a test intake with contact information
    $intake = Intake::create([
        'filename' => 'test_contact_intake.eml',
        'mime_type' => 'message/rfc822',
        'file_path' => 'test/path',
        'customer_name' => 'Test Contact Company Inc',
        'contact_email' => 'testcontact@company.com',
        'contact_name' => 'Bob Johnson',
        'contact_phone' => '+1234567999',
        'status' => 'uploaded',
        'extraction_data' => [
            'customer_name' => 'Test Contact Company Inc',
            'contact_email' => 'testcontact@company.com',
            'contact_name' => 'Bob Johnson',
            'contact_phone' => '+1234567999',
        ]
    ]);

    echo "✓ Created test intake with ID: {$intake->id}\n";

    // Process the intake
    echo "2. Processing intake...\n";
    $job = new ProcessIntake($intake);
    $job->handle();

    // Check the result
    $intake->refresh();
    echo "✓ Intake processed\n";
    echo "Client ID: {$intake->robaws_client_id}\n";
    echo "Status: {$intake->status}\n";

    // Clean up
    $intake->delete();
    echo "✓ Test intake cleaned up\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\nDone. Check the Laravel logs for contact creation details.\n";

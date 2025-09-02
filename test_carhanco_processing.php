<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessIntake;
use App\Models\Intake;

echo "Testing Carhanco intake processing with existing client validation...\n";

$intake = Intake::find(3);
if (!$intake) {
    echo "Intake #3 not found\n";
    exit(1);
}

echo "Before processing:\n";
echo "  Status: {$intake->status}\n";
echo "  Customer: {$intake->customer_name}\n";
echo "  Email: " . ($intake->contact_email ?: 'NULL') . "\n";
echo "  Phone: " . ($intake->contact_phone ?: 'NULL') . "\n";

// Run the processing job
$job = new ProcessIntake($intake);
$job->handle();

// Refresh and check results
$intake->refresh();

echo "\nAfter processing:\n";
echo "  Status: {$intake->status}\n";
echo "  Customer: {$intake->customer_name}\n";
echo "  Email: " . ($intake->contact_email ?: 'NULL') . "\n";
echo "  Phone: " . ($intake->contact_phone ?: 'NULL') . "\n";
echo "  Error: " . ($intake->last_export_error ?: 'None') . "\n";

if ($intake->status === 'processed') {
    echo "\n🎉 SUCCESS: Intake is now processed and ready for export!\n";
} else {
    echo "\n❌ Still needs contact info despite having customer name\n";
}

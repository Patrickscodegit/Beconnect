<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Intake;
use App\Jobs\ProcessIntake;

echo "=== Testing Fixed ProcessIntake Job ===\n";

// Test with one of the failed intakes
$intake = Intake::find(3);
if (!$intake) {
    echo "Intake 3 not found!\n";
    exit(1);
}

echo "Testing ProcessIntake for Intake 3:\n";
echo "Current status: {$intake->status}\n";
echo "Current customer: " . ($intake->customer_name ?: 'None') . "\n";
echo "Current email: " . ($intake->contact_email ?: 'None') . "\n";
echo "Current extraction data: " . json_encode($intake->extraction_data, JSON_PRETTY_PRINT) . "\n";

echo "\nDispatching ProcessIntake job...\n";
ProcessIntake::dispatch($intake);

echo "Job dispatched. Check Horizon for processing results.\n";

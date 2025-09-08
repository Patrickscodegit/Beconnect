<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Intake;
use App\Jobs\ProcessIntake;

echo "=== Dispatching Fresh ProcessIntake Jobs ===\n";

// Get the failed intakes with good extraction data
$failedIntakes = [3, 4, 5]; // These have extraction data but are still failed

foreach ($failedIntakes as $intakeId) {
    $intake = Intake::find($intakeId);
    if ($intake) {
        echo "Dispatching ProcessIntake for intake {$intakeId}...\n";
        echo "  Current status: {$intake->status}\n";
        echo "  Has extraction data: " . (!empty($intake->extraction_data) ? 'Yes' : 'No') . "\n";
        
        // Reset status to allow reprocessing
        $intake->update(['status' => 'pending']);
        
        ProcessIntake::dispatch($intake);
        echo "  Job dispatched!\n\n";
    }
}

echo "All jobs dispatched. Monitor Horizon for results.\n";

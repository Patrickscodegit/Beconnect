<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Intake;
use App\Jobs\ProcessIntake;

echo "=== Manually Dispatching ProcessIntake Jobs ===\n";

// Get pending intakes that haven't been exported
$pendingIntakes = Intake::whereIn('id', [2,3,4,5,6])
    ->whereNull('robaws_offer_id')
    ->get();

foreach ($pendingIntakes as $intake) {
    echo "Dispatching ProcessIntake for intake {$intake->id}...\n";
    ProcessIntake::dispatch($intake);
}

echo "Dispatched " . $pendingIntakes->count() . " ProcessIntake jobs.\n";
echo "Jobs should be processed by Horizon automatically.\n";

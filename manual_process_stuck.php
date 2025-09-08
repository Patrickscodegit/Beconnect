<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Intake;
use App\Jobs\ExportIntakeToRobawsJob;
use App\Jobs\ProcessIntake;

echo "=== MANUAL PROCESSING OF STUCK INTAKES ===\n\n";

// First, let's process the pending intake
$pendingIntake = Intake::where('status', 'pending')->where('id', 9)->first();
if ($pendingIntake) {
    echo "Processing pending Intake ID: {$pendingIntake->id}\n";
    try {
        // Create ProcessIntake job and run it directly
        $job = new ProcessIntake($pendingIntake);
        $job->handle();
        echo "✅ ProcessIntake completed for Intake {$pendingIntake->id}\n";
        
        // Refresh the model to get updated status
        $pendingIntake->refresh();
        echo "New status: {$pendingIntake->status}\n";
    } catch (\Exception $e) {
        echo "❌ ProcessIntake failed for Intake {$pendingIntake->id}: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

// Then process the export_queued intakes
$exportQueuedIntakes = Intake::whereIn('status', ['export_queued'])->whereIn('id', [4, 5])->get();

foreach ($exportQueuedIntakes as $intake) {
    echo "Processing export_queued Intake ID: {$intake->id}\n";
    try {
        // Create ExportIntakeToRobawsJob and run it directly
        $job = new ExportIntakeToRobawsJob($intake->id);
        $job->handle();
        echo "✅ ExportIntakeToRobawsJob completed for Intake {$intake->id}\n";
        
        // Refresh the model to get updated status
        $intake->refresh();
        echo "New status: {$intake->status}\n";
        echo "Robaws Offer ID: " . ($intake->robaws_offer_id ?: 'none') . "\n";
    } catch (\Exception $e) {
        echo "❌ ExportIntakeToRobawsJob failed for Intake {$intake->id}: " . $e->getMessage() . "\n";
        echo "Exception trace: " . $e->getTraceAsString() . "\n";
    }
    echo str_repeat('-', 80) . "\n";
}

echo "\n=== FINAL STATUS CHECK ===\n";
$allIntakes = Intake::orderBy('id', 'desc')->limit(10)->get();
foreach ($allIntakes as $intake) {
    echo "ID: {$intake->id} | Status: {$intake->status} | Robaws ID: " . ($intake->robaws_offer_id ?: 'none') . "\n";
}

<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Intake;
use App\Jobs\ExportIntakeToRobawsJob;

echo "=== PROCESSING REMAINING INTAKE #9 ===\n\n";

$intake = Intake::find(9);
if ($intake) {
    echo "Processing Intake ID: {$intake->id} (Status: {$intake->status})\n";
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
}

echo "\n=== FINAL PIPELINE STATUS ===\n";
$allIntakes = Intake::orderBy('id', 'desc')->get();
$statusCounts = [];
foreach ($allIntakes as $intake) {
    $statusCounts[$intake->status] = ($statusCounts[$intake->status] ?? 0) + 1;
    if ($intake->id <= 10) {  // Show recent intakes
        echo "ID: {$intake->id} | Status: {$intake->status} | Robaws ID: " . ($intake->robaws_offer_id ?: 'none') . "\n";
    }
}

echo "\nStatus Distribution:\n";
foreach ($statusCounts as $status => $count) {
    echo "  {$status}: {$count} intakes\n";
}

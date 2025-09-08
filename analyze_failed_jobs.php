<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== ANALYZING FAILED JOBS ===\n\n";

$failedJobs = DB::table('failed_jobs')
    ->where('payload', 'like', '%ExportIntakeToRobawsJob%')
    ->orderBy('failed_at', 'desc')
    ->limit(2)
    ->get();

foreach ($failedJobs as $job) {
    echo "Failed Job ID: {$job->id}\n";
    echo "Failed At: {$job->failed_at}\n";
    
    $payload = json_decode($job->payload, true);
    echo "Job Class: " . ($payload['displayName'] ?? 'Unknown') . "\n";
    
    if (isset($payload['data']['commandName'])) {
        $commandData = unserialize($payload['data']['command']);
        if (isset($commandData->intakeId)) {
            echo "Intake ID: {$commandData->intakeId}\n";
        }
    }
    
    echo "Exception Preview:\n";
    echo substr($job->exception, 0, 1000) . "\n";
    echo str_repeat('-', 80) . "\n\n";
}

echo "=== CURRENT PIPELINE STATUS ===\n";

// Check current status
$intakes = DB::table('intakes')
    ->whereIn('status', ['export_queued', 'pending'])
    ->orderBy('created_at', 'desc')
    ->get();

foreach ($intakes as $intake) {
    echo "Intake ID: {$intake->id} | Status: {$intake->status} | Robaws ID: " . ($intake->robaws_offer_id ?: 'none') . "\n";
}

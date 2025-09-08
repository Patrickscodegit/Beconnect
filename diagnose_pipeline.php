<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Intake;
use Illuminate\Support\Facades\DB;

echo "=== PIPELINE DIAGNOSIS ===\n\n";

// 1. Check recent intakes
echo "1. RECENT INTAKES STATUS:\n";
$intakes = Intake::orderBy('created_at', 'desc')->limit(10)->get();
foreach ($intakes as $intake) {
    echo "ID: {$intake->id} | Status: {$intake->status} | Robaws ID: " . ($intake->robaws_offer_id ?: 'none') . " | Created: {$intake->created_at}\n";
}

echo "\n2. STATUS DISTRIBUTION:\n";
$statusCounts = Intake::select('status', DB::raw('count(*) as count'))
    ->groupBy('status')
    ->get();
foreach ($statusCounts as $status) {
    echo "Status '{$status->status}': {$status->count} intakes\n";
}

// 3. Check failed jobs
echo "\n3. FAILED JOBS:\n";
$failedJobs = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->limit(5)->get();
if ($failedJobs->count() > 0) {
    foreach ($failedJobs as $job) {
        $payload = json_decode($job->payload, true);
        $jobClass = $payload['displayName'] ?? 'Unknown';
        echo "Failed Job: {$jobClass} | Failed at: {$job->failed_at}\n";
        echo "Exception: " . substr($job->exception, 0, 200) . "...\n\n";
    }
} else {
    echo "No failed jobs found.\n";
}

// 4. Check pending jobs
echo "\n4. PENDING JOBS IN QUEUE:\n";
try {
    $jobsCount = DB::table('jobs')->count();
    echo "Jobs in queue: {$jobsCount}\n";
    
    if ($jobsCount > 0) {
        $jobs = DB::table('jobs')->orderBy('created_at', 'desc')->limit(5)->get();
        foreach ($jobs as $job) {
            $payload = json_decode($job->payload, true);
            $jobClass = $payload['displayName'] ?? 'Unknown';
            echo "Queued Job: {$jobClass} | Queue: {$job->queue} | Attempts: {$job->attempts}\n";
        }
    }
} catch (\Exception $e) {
    echo "Error checking jobs table: " . $e->getMessage() . "\n";
}

// 5. Check intakes without export attempts
echo "\n5. INTAKES NEEDING EXPORT:\n";
$needingExport = Intake::whereIn('status', ['pending', 'extracted'])
    ->whereNull('robaws_offer_id')
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

if ($needingExport->count() > 0) {
    echo "Found {$needingExport->count()} intakes needing export:\n";
    foreach ($needingExport as $intake) {
        echo "ID: {$intake->id} | Status: {$intake->status} | Created: {$intake->created_at}\n";
    }
} else {
    echo "No intakes needing export found.\n";
}

echo "\n=== DIAGNOSIS COMPLETE ===\n";

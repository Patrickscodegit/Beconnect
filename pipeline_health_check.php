<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Intake;
use App\Jobs\ProcessIntake;
use Illuminate\Support\Facades\DB;

echo "=== PIPELINE STATUS SUMMARY ===\n\n";

// Check overall status
$statusCounts = Intake::select('status', DB::raw('count(*) as count'))
    ->groupBy('status')
    ->get();

echo "Current Status Distribution:\n";
foreach ($statusCounts as $status) {
    echo "  {$status->status}: {$status->count} intakes\n";
}

// Check recent intakes
echo "\nRecent Intakes (last 10):\n";
$recent = Intake::orderBy('created_at', 'desc')->limit(10)->get();
foreach ($recent as $intake) {
    $status = $intake->status;
    $robawsId = $intake->robaws_offer_id ?: 'none';
    $created = $intake->created_at->format('Y-m-d H:i:s');
    echo "  ID: {$intake->id} | Status: {$status} | Robaws: {$robawsId} | Created: {$created}\n";
}

// Check for any stuck jobs
echo "\nQueue Status:\n";
$queuedJobs = DB::table('jobs')->count();
$failedJobs = DB::table('failed_jobs')->count();
echo "  Queued Jobs: {$queuedJobs}\n";
echo "  Failed Jobs: {$failedJobs}\n";

if ($failedJobs > 0) {
    echo "\nRecent Failed Jobs:\n";
    $failed = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->limit(3)->get();
    foreach ($failed as $job) {
        $payload = json_decode($job->payload, true);
        $jobClass = $payload['displayName'] ?? 'Unknown';
        echo "  {$jobClass} failed at {$job->failed_at}\n";
    }
}

// Check problematic intakes
$problematic = Intake::whereIn('status', ['pending', 'export_queued'])
    ->orWhere(function($q) {
        $q->where('status', 'failed')
          ->where('created_at', '>', now()->subDay());
    })
    ->get();

if ($problematic->count() > 0) {
    echo "\nIntakes Needing Attention:\n";
    foreach ($problematic as $intake) {
        echo "  ID: {$intake->id} | Status: {$intake->status} | Error: " . ($intake->last_export_error ?: 'none') . "\n";
    }
} else {
    echo "\n✅ No intakes needing attention - pipeline is healthy!\n";
}

echo "\n=== SUMMARY ===\n";
$completedCount = Intake::where('status', 'completed')->count();
$totalCount = Intake::count();
$successRate = $totalCount > 0 ? round(($completedCount / $totalCount) * 100, 1) : 0;

echo "Pipeline Success Rate: {$completedCount}/{$totalCount} ({$successRate}%)\n";
echo "Horizon Status: " . (shell_exec('php artisan horizon:status 2>/dev/null') ? "Running" : "Check manually") . "\n";
echo "Laravel Server: " . (file_exists('/tmp/laravel_server.pid') ? "Running" : "Check manually") . "\n";

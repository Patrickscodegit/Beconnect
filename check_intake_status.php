<?php

require_once __DIR__ . '/bootstrap/app.php';

use App\Models\Intake;
use Illuminate\Support\Facades\Log;

// Check the status of intake 4
$intake = Intake::with('files')->find(4);

if (!$intake) {
    echo "❌ Intake 4 not found\n";
    exit(1);
}

echo "📋 Intake Status Check\n";
echo "=====================\n";
echo "ID: {$intake->id}\n";
echo "Status: {$intake->status}\n";
echo "Robaws Client ID: " . ($intake->robaws_client_id ?? 'None') . "\n";
echo "Last Error: " . ($intake->last_error ?? 'None') . "\n";
echo "Updated: {$intake->updated_at}\n";

if ($intake->status === 'exported') {
    echo "✅ Export successful!\n";
} else {
    echo "⚠️  Export status: {$intake->status}\n";
}

// Show recent queue jobs for this intake
$recentJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')
    ->where('payload', 'like', '%intake":4%')
    ->orWhere('payload', 'like', '%intake_id":4%')
    ->orderBy('failed_at', 'desc')
    ->limit(3)
    ->get();

if ($recentJobs->count() > 0) {
    echo "\n🚨 Recent Failed Jobs:\n";
    foreach ($recentJobs as $job) {
        echo "- Failed at: {$job->failed_at}\n";
        $exception = json_decode($job->exception, true);
        if (is_string($exception)) {
            // Get first line of exception
            $lines = explode("\n", $exception);
            echo "  Error: " . (isset($lines[0]) ? substr($lines[0], 0, 100) : 'Unknown') . "\n";
        }
    }
}

echo "\nCheck completed.\n";

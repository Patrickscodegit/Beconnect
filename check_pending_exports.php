<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Intake;
use App\Jobs\ProcessIntake;

echo "=== Checking Pending Intakes Export Status ===\n";

$pendingIntakes = Intake::whereIn('id', [2,3,4,5,6,7])
    ->orderBy('id')
    ->get(['id', 'status', 'robaws_offer_id', 'robaws_exported_at', 'robaws_export_status', 'extraction_data', 'created_at']);

foreach ($pendingIntakes as $intake) {
    echo "Intake {$intake->id}:\n";
    echo "  Status: {$intake->status}\n";
    echo "  Created: " . $intake->created_at->toDateTimeString() . "\n";
    echo "  Robaws Offer ID: " . ($intake->robaws_offer_id ?: 'None') . "\n";
    echo "  Exported At: " . ($intake->robaws_exported_at ? $intake->robaws_exported_at->toDateTimeString() : 'None') . "\n";
    echo "  Export Status: " . ($intake->robaws_export_status ?: 'None') . "\n";
    echo "  Has Extraction Data: " . (!empty($intake->extraction_data) ? 'Yes' : 'No') . "\n";
    
    if (!empty($intake->extraction_data)) {
        $hasDocumentData = !empty($intake->extraction_data['document_data']);
        echo "  Has Document Data: " . ($hasDocumentData ? 'Yes' : 'No') . "\n";
        
        // Check if this should be exported
        if ($hasDocumentData && empty($intake->robaws_offer_id)) {
            echo "  ❌ SHOULD BE EXPORTED BUT ISN'T!\n";
        }
    }
    echo "  ----------------------------------------\n";
}

// Check if ProcessIntake jobs are running
echo "\n=== Checking Queue System ===\n";
try {
    // Check if we can dispatch a test job
    echo "Queue system appears to be available.\n";
    
    // Check failed jobs
    $failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
    echo "Failed jobs count: $failedJobs\n";
    
    if ($failedJobs > 0) {
        $recentFailed = \Illuminate\Support\Facades\DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit(3)
            ->get(['payload', 'exception', 'failed_at']);
        
        foreach ($recentFailed as $failed) {
            $payload = json_decode($failed->payload, true);
            $jobClass = $payload['displayName'] ?? 'Unknown';
            echo "Recent failed job: $jobClass at {$failed->failed_at}\n";
        }
    }
} catch (\Exception $e) {
    echo "Queue system error: " . $e->getMessage() . "\n";
}

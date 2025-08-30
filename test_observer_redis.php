<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🔧 TESTING OBSERVER WITH REDIS QUEUE\n";
echo "====================================\n\n";

// Get a test document
$doc = \App\Models\Document::whereNotNull('robaws_quotation_id')->first();
if (!$doc) {
    echo "No test document found\n";
    exit;
}

echo "Using document: {$doc->id} ({$doc->filename})\n";
$original = $doc->robaws_quotation_id;
$testId = 'TEST-OBSERVER-' . time();

echo "Original quotation ID: {$original}\n";
echo "Test quotation ID: {$testId}\n\n";

// Get Redis queue length before
$redis = \Illuminate\Support\Facades\Redis::connection();
$queueKey = 'vehicle_data_extraction_horizon:queue:default';

try {
    $beforeLength = $redis->zcard($queueKey); // Horizon uses sorted sets
} catch (Exception $e) {
    echo "Redis queue check error: " . $e->getMessage() . "\n";
    $beforeLength = 'unknown';
}

echo "Queue items before: {$beforeLength}\n";

// Update to trigger observer
echo "Updating robaws_quotation_id to trigger observer...\n";
$doc->update(['robaws_quotation_id' => $testId]);

// Check queue after
try {
    $afterLength = $redis->zcard($queueKey);
} catch (Exception $e) {
    $afterLength = 'unknown';
}

echo "Queue items after: {$afterLength}\n";

$jobsAdded = ($beforeLength !== 'unknown' && $afterLength !== 'unknown') 
    ? ($afterLength - $beforeLength) 
    : 'unknown';

echo "Jobs added: {$jobsAdded}\n\n";

if ($jobsAdded > 0) {
    echo "✅ SUCCESS: Observer created {$jobsAdded} job(s)!\n";
    echo "The DocumentObserver is working correctly.\n";
} else {
    echo "❌ PROBLEM: Observer did not create any jobs.\n";
    
    // Test manual dispatch
    echo "Testing manual dispatch...\n";
    \App\Jobs\UploadDocumentToRobaws::dispatch($doc, $testId);
    
    try {
        $manualLength = $redis->zcard($queueKey);
        $manualAdded = $manualLength - $afterLength;
        echo "Manual dispatch added: {$manualAdded} job(s)\n";
    } catch (Exception $e) {
        echo "Could not check manual dispatch result\n";
    }
}

// Restore original value
$doc->update(['robaws_quotation_id' => $original]);
echo "\nRestored original quotation ID: {$original}\n";

echo "\n" . str_repeat("=", 50) . "\n";
echo "NEXT STEP: Process the queue with 'php artisan queue:work' or 'php artisan horizon'\n";

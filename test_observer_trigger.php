<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🔧 TESTING OBSERVER TRIGGER\n";
echo "===========================\n\n";

// Get a test document
$doc = \App\Models\Document::whereNotNull('robaws_quotation_id')->first();
if (!$doc) {
    echo "No test document found\n";
    exit;
}

echo "Using document: {$doc->id} ({$doc->filename})\n";
$original = $doc->robaws_quotation_id;
$testId = 'TEST-' . time();

echo "Original quotation ID: {$original}\n";
echo "Test quotation ID: {$testId}\n\n";

// Count jobs before
$jobsBefore = \DB::table('jobs')->count();
echo "Jobs before update: {$jobsBefore}\n";

// Update to trigger observer
echo "Updating robaws_quotation_id...\n";
$doc->update(['robaws_quotation_id' => $testId]);

// Count jobs after
$jobsAfter = \DB::table('jobs')->count();
echo "Jobs after update: {$jobsAfter}\n";

// Check for upload jobs specifically
$uploadJobs = \DB::table('jobs')
    ->where('payload', 'like', '%UploadDocumentToRobaws%')
    ->where('created_at', '>', now()->subMinute())
    ->count();

echo "Upload jobs created: {$uploadJobs}\n\n";

if ($uploadJobs === 0) {
    echo "❌ PROBLEM: DocumentObserver is NOT working!\n";
    echo "The observer should have created an UploadDocumentToRobaws job.\n\n";
    
    echo "Manually testing job dispatch...\n";
    \App\Jobs\UploadDocumentToRobaws::dispatch($doc, $testId);
    
    $manualJobs = \DB::table('jobs')->count();
    echo "Jobs after manual dispatch: {$manualJobs}\n";
    
    if ($manualJobs > $jobsAfter) {
        echo "✅ Manual dispatch works - the issue is with the observer\n";
    } else {
        echo "❌ Manual dispatch also failed - check queue configuration\n";
    }
} else {
    echo "✅ SUCCESS: Observer created {$uploadJobs} upload job(s)\n";
}

// Restore original value
$doc->update(['robaws_quotation_id' => $original]);
echo "\nRestored original quotation ID: {$original}\n";

echo "\n" . str_repeat("=", 50) . "\n";
echo "DIAGNOSIS: " . ($uploadJobs > 0 ? "Observer is working" : "Observer is NOT working") . "\n";

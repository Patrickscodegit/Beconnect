<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🔍 PRODUCTION ENVIRONMENT DEBUG\n";
echo "===============================\n\n";

echo "🌍 Environment Details:\n";
echo "  APP_ENV: " . config('app.env') . "\n";
echo "  APP_DEBUG: " . (config('app.debug') ? 'true' : 'false') . "\n";
echo "  PHP Version: " . PHP_VERSION . "\n";
echo "  Laravel Version: " . app()->version() . "\n\n";

echo "🔧 Storage Configuration:\n";
echo "  Default Disk: " . config('filesystems.default') . "\n";
echo "  Local Root: " . config('filesystems.disks.local.root') . "\n";
if (config('filesystems.disks.spaces')) {
    echo "  Spaces Configured: YES\n";
    echo "  Spaces Region: " . config('filesystems.disks.spaces.region') . "\n";
    echo "  Spaces Bucket: " . config('filesystems.disks.spaces.bucket') . "\n";
} else {
    echo "  Spaces Configured: NO\n";
}
echo "\n";

echo "🌐 Robaws Configuration:\n";
echo "  Base URL: " . config('services.robaws.base_url') . "\n";
echo "  Username: " . (config('services.robaws.username') ? 'SET (' . strlen(config('services.robaws.username')) . ' chars)' : 'NOT SET') . "\n";
echo "  Password: " . (config('services.robaws.password') ? 'SET (' . strlen(config('services.robaws.password')) . ' chars)' : 'NOT SET') . "\n\n";

echo "📊 Database Status:\n";
try {
    $documentCount = \App\Models\Document::count();
    echo "  Total Documents: {$documentCount}\n";
    
    $pendingCount = \App\Models\Document::where('processing_status', 'pending')->count();
    echo "  Pending Documents: {$pendingCount}\n";
    
    $failedCount = \App\Models\Document::whereNotNull('processing_error')->count();
    echo "  Failed Documents: {$failedCount}\n";
    
    $robawsCount = \App\Models\Document::whereNotNull('robaws_quotation_id')->count();
    echo "  Documents with Robaws ID: {$robawsCount}\n";
} catch (\Exception $e) {
    echo "  Database Error: " . $e->getMessage() . "\n";
}
echo "\n";

echo "🚨 Recent Errors:\n";
try {
    $recentErrors = \App\Models\Document::whereNotNull('processing_error')
        ->latest()
        ->take(5)
        ->get(['id', 'filename', 'processing_error', 'updated_at']);
    
    if ($recentErrors->count() > 0) {
        foreach ($recentErrors as $doc) {
            echo "  Doc {$doc->id}: {$doc->processing_error}\n";
            echo "    File: {$doc->filename}\n";
            echo "    Time: {$doc->updated_at}\n\n";
        }
    } else {
        echo "  No recent processing errors found\n";
    }
} catch (\Exception $e) {
    echo "  Error fetching recent errors: " . $e->getMessage() . "\n";
}

echo "🧪 Quick Robaws Test:\n";
try {
    $client = new \App\Services\RobawsClient();
    $result = $client->testConnection();
    echo "  Connection: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
    if (!$result['success']) {
        echo "  Error: " . ($result['message'] ?? 'Unknown') . "\n";
    }
} catch (\Exception $e) {
    echo "  Connection Exception: " . $e->getMessage() . "\n";
}

echo "\n🔍 For Forge Deployment:\n";
echo "1. Check .env file has correct ROBAWS credentials\n";
echo "2. Verify storage permissions for document uploads\n";
echo "3. Check Laravel logs: storage/logs/laravel.log\n";
echo "4. Ensure queue workers are running if using queues\n";
echo "5. Verify network connectivity to Robaws API\n";

echo "\n🏁 Environment debug complete!\n";

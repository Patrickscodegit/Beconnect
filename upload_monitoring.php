<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 UPLOAD MONITORING DASHBOARD\n";
echo "==============================\n\n";

echo "📊 SYSTEM HEALTH CHECK\n";
echo "-----------------------\n";

// 1. Check for documents without quotation IDs after processing
$documentsWithoutQuotationId = \App\Models\Document::where('processing_status', 'completed')
    ->whereNull('robaws_quotation_id')
    ->count();

echo "❗ Documents processed but missing quotation ID: {$documentsWithoutQuotationId}\n";

// 2. Check for upload failures
$failedUploads = \App\Models\Document::where('upload_status', 'failed')->count();
echo "❗ Failed uploads: {$failedUploads}\n";

// 3. Check for pending uploads (should be processed quickly)
$pendingUploads = \App\Models\Document::where('upload_status', 'pending')
    ->where('created_at', '<', now()->subMinutes(30))
    ->count();

echo "⚠️  Pending uploads (>30min): {$pendingUploads}\n";

// 4. Recent successful uploads
$recentSuccessful = \App\Models\Document::where('upload_status', 'uploaded')
    ->where('created_at', '>=', now()->subDay())
    ->count();

echo "✅ Successful uploads (last 24h): {$recentSuccessful}\n";

echo "\n📈 WORKFLOW STATISTICS\n";
echo "----------------------\n";

// Documents by processing status
$processingStats = \App\Models\Document::selectRaw('processing_status, count(*) as count')
    ->groupBy('processing_status')
    ->pluck('count', 'processing_status')
    ->toArray();

foreach ($processingStats as $status => $count) {
    echo "Processing {$status}: {$count}\n";
}

echo "\n";

// Upload status distribution
$uploadStats = \App\Models\Document::selectRaw('upload_status, count(*) as count')
    ->whereNotNull('upload_status')
    ->groupBy('upload_status')
    ->pluck('count', 'upload_status')
    ->toArray();

foreach ($uploadStats as $status => $count) {
    echo "Upload {$status}: {$count}\n";
}

echo "\n🚨 ISSUES TO INVESTIGATE\n";
echo "------------------------\n";

if ($documentsWithoutQuotationId > 0) {
    echo "⚠️  {$documentsWithoutQuotationId} completed documents are missing Robaws quotation IDs\n";
    echo "   → Check extraction and integration logs\n";
    echo "   → Run: php artisan queue:work to process pending jobs\n";
}

if ($failedUploads > 0) {
    echo "❌ {$failedUploads} uploads have failed\n";
    echo "   → Check document upload logs\n";
    echo "   → Verify Robaws API connectivity\n";
}

if ($pendingUploads > 0) {
    echo "⏳ {$pendingUploads} uploads have been pending for >30 minutes\n";
    echo "   → Check queue worker status\n";
    echo "   → Run: php artisan queue:work\n";
}

if ($documentsWithoutQuotationId === 0 && $failedUploads === 0 && $pendingUploads === 0) {
    echo "🎉 No issues detected - system is running smoothly!\n";
}

echo "\n💡 PREVENTION COMMANDS\n";
echo "---------------------\n";
echo "Run these regularly to maintain system health:\n";
echo "1. Monitor: php upload_monitoring.php\n";
echo "2. Process queue: php artisan queue:work --stop-when-empty\n";
echo "3. Check logs: tail -f storage/logs/laravel.log | grep -i robaws\n";
echo "4. Fix any issues: php fix_missing_quotation_ids.php\n";

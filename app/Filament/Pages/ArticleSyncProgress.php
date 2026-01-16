<?php

namespace App\Filament\Pages;

use App\Models\RobawsArticleCache;
use App\Jobs\RobawsArticlesFullSyncJob;
use App\Models\RobawsSyncLog;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArticleSyncProgress extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    
    protected static ?string $navigationLabel = 'Sync Progress';
    
    protected static ?string $navigationGroup = 'Quotation System';
    
    protected static ?int $navigationSort = 13;
    
    protected static string $view = 'filament.pages.article-sync-progress';
    
    protected static ?string $title = 'Article Sync Progress';
    
    // Auto-refresh every 5 seconds while sync is running
    protected $listeners = ['refreshProgress' => '$refresh'];
    
    public function mount(): void
    {
        // Start auto-refresh via Livewire polling
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetSyncState')
                ->label('Reset Sync State')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Reset sync state?')
                ->modalDescription('Clears the running flag and last batch ID. Use this if the sync status is stuck.')
                ->action(function (): void {
                    Cache::forget(RobawsArticlesFullSyncJob::RUNNING_CACHE_KEY);
                    Cache::forget(RobawsArticlesFullSyncJob::BATCH_ID_CACHE_KEY);
                }),
            Action::make('forceRebuildSync')
                ->label('Force Rebuild Sync')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Force rebuild sync?')
                ->modalDescription('Queues a full rebuild (clears cache and resyncs). This is expensive and uses API quota.')
                ->action(function (): void {
                    RobawsArticlesFullSyncJob::dispatch(rebuildCache: true);
                }),
        ];
    }
    
    public function getQueueStats(): array
    {
        if (!Schema::hasTable('jobs')) {
            return [
                'pending_jobs' => 0,
                'article_jobs' => 0,
                'failed_jobs' => 0,
                'error' => null,
            ];
        }

        try {
            // Count jobs by checking payload for SyncSingleArticleMetadataJob class name
            // Since we moved to default queue, we need to filter by job class instead of queue name
            $articleJobs = DB::table('jobs')
                ->where('payload', 'LIKE', '%SyncSingleArticleMetadataJob%')
                ->count();

            return [
                'pending_jobs' => DB::table('jobs')->count(),
                'article_jobs' => $articleJobs,
                'failed_jobs' => Schema::hasTable('failed_jobs')
                    ? DB::table('failed_jobs')->count()
                    : 0,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'pending_jobs' => 0,
                'article_jobs' => 0,
                'failed_jobs' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getBatchStats(): array
    {
        if (!Schema::hasTable('job_batches')) {
            return [
                'has_batch' => false,
                'error' => null,
            ];
        }

        try {
            $batchId = Cache::get(RobawsArticlesFullSyncJob::BATCH_ID_CACHE_KEY);

            if ($batchId) {
                $batch = DB::table('job_batches')->where('id', $batchId)->first();
            } else {
                $batch = DB::table('job_batches')
                    ->where('name', 'robaws-articles-full-sync')
                    ->orderByDesc('created_at')
                    ->first();
            }

            if (!$batch) {
                return [
                    'has_batch' => false,
                    'error' => null,
                ];
            }

            $totalJobs = (int) $batch->total_jobs;
            $pendingJobs = (int) $batch->pending_jobs;
            $failedJobs = (int) $batch->failed_jobs;
            $processedJobs = max(0, $totalJobs - $pendingJobs - $failedJobs);
            $pending = max(0, $totalJobs - $pendingJobs - $failedJobs);

            return [
                'has_batch' => true,
                'id' => $batch->id,
                'name' => $batch->name,
                'total_jobs' => $totalJobs,
                'pending_jobs' => $pendingJobs,
                'processed_jobs' => $processedJobs,
                'failed_jobs' => $failedJobs,
                'progress' => (int) round($totalJobs > 0 ? ($processedJobs / $totalJobs) * 100 : 0),
                'pending_calculated' => $pending,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'has_batch' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getQueueReadiness(): array
    {
        $connection = config('queue.default');
        $jobsTable = Schema::hasTable('jobs');
        $batchesTable = Schema::hasTable('job_batches');
        $failedTable = Schema::hasTable('failed_jobs');

        return [
            'connection' => $connection,
            'jobs_table' => $jobsTable,
            'batches_table' => $batchesTable,
            'failed_table' => $failedTable,
            'is_sync' => $connection === 'sync',
        ];
    }

    public function getQueueDiagnostics(): array
    {
        if (!Schema::hasTable('jobs')) {
            return [
                'has_jobs_table' => false,
                'error' => null,
            ];
        }

        try {
            $oldestJob = DB::table('jobs')->orderBy('created_at')->first();
            $latestJob = DB::table('jobs')->orderByDesc('created_at')->first();

            return [
                'has_jobs_table' => true,
                'queue_size' => DB::table('jobs')->count(),
                'oldest_job_at' => $oldestJob?->created_at,
                'latest_job_at' => $latestJob?->created_at,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'has_jobs_table' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getLastSyncLog(): ?RobawsSyncLog
    {
        try {
            return RobawsSyncLog::where('sync_type', 'articles_full')
                ->orderByDesc('started_at')
                ->first();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getStaleSyncWarning(): ?string
    {
        $isRunningFlag = Cache::get(RobawsArticlesFullSyncJob::RUNNING_CACHE_KEY, false);
        $batchStats = $this->getBatchStats();
        $queueStats = $this->getQueueStats();

        $hasBatch = ($batchStats['has_batch'] ?? false) && (($batchStats['total_jobs'] ?? 0) > 0);
        $hasQueuedJobs = $queueStats['pending_jobs'] > 0 || $queueStats['article_jobs'] > 0;

        if ($isRunningFlag && !$hasBatch && !$hasQueuedJobs) {
            Cache::forget(RobawsArticlesFullSyncJob::RUNNING_CACHE_KEY);
            return 'Sync marked as running but no jobs or batch were found. The flag was reset.';
        }

        if (($batchStats['has_batch'] ?? false) && (($batchStats['total_jobs'] ?? 0) === 0)) {
            return 'Latest sync batch has zero jobs. This usually indicates a stale batch record.';
        }

        return null;
    }
    
    public function getFieldStats(): array
    {
        try {
            $total = RobawsArticleCache::count();

            // Optimization metrics: Articles with extraFields don't need API calls
            $articlesWithExtraFields = RobawsArticleCache::where(function ($query) {
                $query->whereNotNull('shipping_line')
                    ->orWhereNotNull('transport_mode')
                    ->orWhereNotNull('pol_terminal');
            })->count();

            $articlesWithoutExtraFields = max(0, $total - $articlesWithExtraFields);

            // Estimated API calls saved per full sync
            $apiCallsSavedPerSync = $articlesWithExtraFields;
            $optimizationPercentage = $total > 0 ? round(($apiCallsSavedPerSync / $total) * 100) : 0;

            return [
                'total' => $total,
                'parent_items' => RobawsArticleCache::where('is_parent_item', true)->count(),
                'with_commodity' => RobawsArticleCache::whereNotNull('commodity_type')->count(),
                'with_pod_code' => RobawsArticleCache::whereNotNull('pod_code')->count(),
                'with_pol_terminal' => RobawsArticleCache::whereNotNull('pol_terminal')->count(),
                'with_shipping_line' => RobawsArticleCache::whereNotNull('shipping_line')->count(),
                // Optimization metrics
                'articles_with_extraFields' => $articlesWithExtraFields,
                'articles_without_extraFields' => $articlesWithoutExtraFields,
                'api_calls_saved_per_sync' => $apiCallsSavedPerSync,
                'optimization_percentage' => $optimizationPercentage,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'total' => 0,
                'parent_items' => 0,
                'with_commodity' => 0,
                'with_pod_code' => 0,
                'with_pol_terminal' => 0,
                'with_shipping_line' => 0,
                'articles_with_extraFields' => 0,
                'articles_without_extraFields' => 0,
                'api_calls_saved_per_sync' => 0,
                'optimization_percentage' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    public function getOptimizationStats(): array
    {
        try {
            // Get webhook stats for optimization metrics
            $webhooksLast24h = \App\Models\RobawsWebhookLog::where('created_at', '>=', now()->subDay())
                ->where('status', 'processed')
                ->count();
            $apiCallsSavedFromWebhooks = $webhooksLast24h * 2; // 2 API calls saved per webhook

            // Get average processing time for webhooks (optimization: should be <100ms)
            $avgProcessingTime = \App\Models\RobawsWebhookLog::where('created_at', '>=', now()->subDay())
                ->whereNotNull('processing_duration_ms')
                ->where('processing_duration_ms', '>', 0)
                ->avg('processing_duration_ms');
            $avgProcessingTimeMs = $avgProcessingTime ? round($avgProcessingTime, 0) : 0;

            $fieldStats = $this->getFieldStats();

            return [
                'webhooks_last24h' => $webhooksLast24h,
                'api_calls_saved_from_webhooks' => $apiCallsSavedFromWebhooks,
                'avg_webhook_processing_time_ms' => $avgProcessingTimeMs,
                'articles_with_extraFields' => $fieldStats['articles_with_extraFields'],
                'articles_without_extraFields' => $fieldStats['articles_without_extraFields'],
                'api_calls_saved_per_sync' => $fieldStats['api_calls_saved_per_sync'],
                'optimization_percentage' => $fieldStats['optimization_percentage'],
                'error' => $fieldStats['error'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'webhooks_last24h' => 0,
                'api_calls_saved_from_webhooks' => 0,
                'avg_webhook_processing_time_ms' => 0,
                'articles_with_extraFields' => 0,
                'articles_without_extraFields' => 0,
                'api_calls_saved_per_sync' => 0,
                'optimization_percentage' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    public function getRecentArticles(): array
    {
        try {
            return RobawsArticleCache::orderBy('updated_at', 'desc')
                ->take(10)
                ->get()
                ->map(fn($article) => [
                    'name' => $article->article_name,
                    'updated_at' => $article->updated_at,
                    'is_parent' => $article->is_parent_item,
                    'commodity_type' => $article->commodity_type,
                    'pod_code' => $article->pod_code,
                ])
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }
    
    public function getSyncStatus(): string
    {
        $stats = $this->getQueueStats();
        $batchStats = $this->getBatchStats();
        $isRunningFlag = Cache::get(RobawsArticlesFullSyncJob::RUNNING_CACHE_KEY, false);

        $hasBatch = ($batchStats['has_batch'] ?? false) && (($batchStats['total_jobs'] ?? 0) > 0);
        $hasQueuedJobs = $stats['pending_jobs'] > 0 || $stats['article_jobs'] > 0;

        if ($isRunningFlag && !$hasBatch && !$hasQueuedJobs) {
            Cache::forget(RobawsArticlesFullSyncJob::RUNNING_CACHE_KEY);
            $isRunningFlag = false;
        }
        
        // State 1: Syncing - Jobs in queue
        if ($hasBatch) {
            if (($batchStats['pending_jobs'] ?? 0) > 0 || ($batchStats['processed_jobs'] ?? 0) < ($batchStats['total_jobs'] ?? 0)) {
                return 'running';
            }
        }

        if ($isRunningFlag || $hasQueuedJobs) {
            return 'running';
        }
        
        // State 2 & 3: Check field population to distinguish complete vs. idle
        $fieldStats = $this->getFieldStats();
        
        // Calculate overall population percentage (average of key fields)
        $keyFieldsPopulated = (
            ($fieldStats['with_commodity'] > 0 ? 1 : 0) +
            ($fieldStats['with_pod_code'] > 0 ? 1 : 0) +
            ($fieldStats['with_shipping_line'] > 0 ? 1 : 0)
        );
        
        $commodityPercentage = $fieldStats['total'] > 0
            ? ($fieldStats['with_commodity'] / $fieldStats['total']) * 100
            : 0;
        
        // State 2: Sync Complete - At least 2 key fields populated OR commodity > 50%
        if ($keyFieldsPopulated >= 2 || $commodityPercentage > 50) {
            return 'complete';
        }
        
        // State 3: No Sync Running - Never synced or minimal data
        return 'idle';
    }
    
    public function getProgressPercentage(): int
    {
        $batchStats = $this->getBatchStats();
        if ($batchStats['has_batch'] ?? false) {
            return (int) $batchStats['progress'];
        }

        $fieldStats = $this->getFieldStats();
        if ($fieldStats['total'] <= 0) {
            return 0;
        }

        return (int) round(($fieldStats['with_commodity'] / $fieldStats['total']) * 100);
    }
    
    public function getEstimatedTimeRemaining(): ?string
    {
        $stats = $this->getQueueStats();
        $pendingJobs = $stats['article_jobs']; // Use article_jobs count for accuracy
        
        if ($pendingJobs === 0) {
            return null;
        }
        
        // Each job takes ~0.5 seconds (safe rate limit: 2 req/sec)
        $secondsRemaining = $pendingJobs * 0.5;
        $minutesRemaining = ceil($secondsRemaining / 60);
        
        if ($minutesRemaining < 60) {
            return "{$minutesRemaining} minutes";
        }
        
        $hours = floor($minutesRemaining / 60);
        $minutes = $minutesRemaining % 60;
        return "{$hours}h {$minutes}m";
    }
}


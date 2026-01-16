<?php

namespace App\Jobs;

use App\Models\RobawsArticleCache;
use App\Models\RobawsSyncLog;
use App\Services\Quotation\RobawsArticlesSyncService;
use App\Jobs\DispatchArticleExtraFieldsSyncJobs;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RobawsArticlesFullSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const RUNNING_CACHE_KEY = 'robaws:articles:full_sync_running';
    public const BATCH_ID_CACHE_KEY = 'robaws:articles:full_sync_batch_id';

    public $timeout = 900; // 15 minutes for full sync orchestration

    public function __construct(
        public bool $rebuildCache = false,
        public int $metadataChunkSize = 100,
        public float $metadataDelaySeconds = 0.25,
        public int $extraFieldsBatchSize = 50,
        public float $extraFieldsDelaySeconds = 0.5
    ) {}

    public function handle(RobawsArticlesSyncService $syncService): void
    {
        if (Cache::get(self::RUNNING_CACHE_KEY)) {
            Log::warning('Robaws full sync already running, skipping duplicate dispatch.');
            return;
        }

        Cache::put(self::RUNNING_CACHE_KEY, true, now()->addHours(6));
        $syncLog = RobawsSyncLog::create([
            'sync_type' => 'articles_full',
            'started_at' => now(),
        ]);

        try {
            Log::info('Starting full Robaws articles sync job', [
                'rebuild_cache' => $this->rebuildCache,
            ]);

            if ($this->rebuildCache) {
                $syncService->rebuildCache(syncMetadata: false);
            } else {
                $syncService->sync();
            }

            $articleIds = RobawsArticleCache::orderBy('id')->pluck('id')->toArray();
            $metadataJobs = [];
            $delay = 0.0;

            foreach (array_chunk($articleIds, $this->metadataChunkSize) as $chunk) {
                $job = (new SyncArticlesMetadataChunkJob($chunk, useApi: false))
                    ->delay(now()->addSeconds($delay));
                $metadataJobs[] = $job;
                $delay += $this->metadataDelaySeconds;
            }

            if (!empty($metadataJobs)) {
                $metadataJobs[] = (new DispatchArticleExtraFieldsSyncJobs(
                    batchSize: $this->extraFieldsBatchSize,
                    delaySeconds: (int) ceil($this->extraFieldsDelaySeconds)
                ))->delay(now()->addSeconds($delay));
            }

            if (empty($metadataJobs)) {
                Cache::forget(self::RUNNING_CACHE_KEY);
                $syncLog->markAsCompleted(count($articleIds));
                Log::warning('Robaws full sync completed without any metadata jobs (no articles found).');
                return;
            }

            $batch = Bus::batch($metadataJobs)
                ->name('robaws-articles-full-sync')
                ->then(function (Batch $batch) use ($syncLog) {
                    Cache::forget(self::RUNNING_CACHE_KEY);
                    $syncLog->markAsCompleted(RobawsArticleCache::count());
                    Log::info('Robaws full sync batch completed', [
                        'batch_id' => $batch->id,
                    ]);
                })
                ->catch(function (Batch $batch, Throwable $e) use ($syncLog) {
                    Cache::forget(self::RUNNING_CACHE_KEY);
                    $syncLog->markAsFailed($e->getMessage());
                    Log::error('Robaws full sync batch failed', [
                        'batch_id' => $batch->id,
                        'error' => $e->getMessage(),
                    ]);
                })
                ->dispatch();

            Cache::put(self::BATCH_ID_CACHE_KEY, $batch->id, now()->addHours(24));

            Log::info('Robaws full sync batch dispatched', [
                'batch_id' => $batch->id,
                'metadata_jobs' => count($metadataJobs),
            ]);
        } catch (\Exception $e) {
            Cache::forget(self::RUNNING_CACHE_KEY);
            $syncLog->markAsFailed($e->getMessage());
            Log::error('Robaws full sync job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}

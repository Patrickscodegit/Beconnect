<?php

namespace App\Jobs;

use App\Models\RobawsArticleCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncArticlesMetadataBulkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 600; // 10 minutes for dispatching jobs

    /**
     * Create a new job instance.
     *
     * @param array|string $articleIds Array of IDs or 'all' to sync all articles
     * @param int $chunkSize Number of articles per chunk job
     * @param float $delaySeconds Delay between chunk jobs for rate limiting
     * @param bool $useApi Whether to use Robaws API during metadata sync
     */
    public function __construct(
        public array|string $articleIds = 'all',
        public int $chunkSize = 100,
        public float $delaySeconds = 0.25,
        public bool $useApi = false
    ) {}

    /**
     * Execute the job.
     * Dispatches individual SyncSingleArticleMetadataJob for each article
     */
    public function handle(): void
    {
        // Resolve article IDs
        if ($this->articleIds === 'all') {
            $articles = RobawsArticleCache::pluck('id')->toArray();
        } else {
            $articles = $this->articleIds;
        }
        
        $totalArticles = count($articles);
        
        Log::info('Starting bulk metadata sync - dispatching individual jobs', [
            'article_count' => $totalArticles,
            'mode' => $this->articleIds === 'all' ? 'all articles' : 'specific IDs'
        ]);
        
        // Chunk articles into batches to avoid memory issues
        $batches = array_chunk($articles, $this->chunkSize);
        $dispatched = 0;
        $delay = 0.0;

        foreach ($batches as $batchIndex => $batch) {
            SyncArticlesMetadataChunkJob::dispatch($batch, useApi: $this->useApi)
                ->delay(now()->addSeconds($delay));

            $dispatched += count($batch);
            $delay += $this->delaySeconds;

            Log::info('Dispatched metadata chunk job', [
                'batch' => $batchIndex + 1,
                'total_batches' => count($batches),
                'dispatched_so_far' => $dispatched,
                'total' => $totalArticles,
                'progress_percent' => $totalArticles > 0
                    ? round(($dispatched / $totalArticles) * 100, 2)
                    : 0,
                'current_delay_seconds' => $delay,
            ]);
        }

        Log::info('Completed dispatching bulk metadata sync jobs', [
            'total_dispatched' => $dispatched,
            'queue' => 'default',
            'message' => 'All chunk jobs queued for metadata sync',
        ]);
    }
}

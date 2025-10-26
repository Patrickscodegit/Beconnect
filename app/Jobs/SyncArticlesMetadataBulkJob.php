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
     */
    public function __construct(
        public array|string $articleIds = 'all'
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
        $batches = array_chunk($articles, 50);
        $dispatched = 0;
        
        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $articleId) {
                // Dispatch individual job to default queue (processed by existing worker)
                SyncSingleArticleMetadataJob::dispatch($articleId);
                
                $dispatched++;
            }
            
            Log::info('Dispatched batch of metadata sync jobs', [
                'batch' => $batchIndex + 1,
                'total_batches' => count($batches),
                'dispatched_so_far' => $dispatched,
                'total' => $totalArticles,
                'progress_percent' => round(($dispatched / $totalArticles) * 100, 2)
            ]);
        }
        
        Log::info('Completed dispatching bulk metadata sync jobs', [
            'total_dispatched' => $dispatched,
            'queue' => 'default',
            'message' => "All jobs queued and will be processed by existing queue worker"
        ]);
    }
}

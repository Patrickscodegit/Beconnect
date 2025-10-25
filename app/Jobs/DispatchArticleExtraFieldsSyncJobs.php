<?php

namespace App\Jobs;

use App\Models\RobawsArticleCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchArticleExtraFieldsSyncJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes to dispatch all jobs

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $batchSize = 50,
        public int $delaySeconds = 2
    ) {
        // This dispatcher runs on the default queue
    }

    /**
     * Execute the job - dispatch individual sync jobs for all articles with rate limiting.
     */
    public function handle(): void
    {
        Log::info('Starting dispatch of article extra fields sync jobs', [
            'batch_size' => $this->batchSize,
            'delay_seconds' => $this->delaySeconds
        ]);

        $totalArticles = RobawsArticleCache::count();
        $dispatchedCount = 0;
        $delay = 0; // Start with no delay for first job

        Log::info('Total articles to sync', ['count' => $totalArticles]);

        // Process in chunks to avoid memory issues
        RobawsArticleCache::orderBy('id')
            ->chunk($this->batchSize, function ($articles) use (&$dispatchedCount, &$delay) {
                foreach ($articles as $article) {
                    // Dispatch job with incremental delay for rate limiting
                    SyncSingleArticleMetadataJob::dispatch($article->id)
                        ->delay(now()->addSeconds($delay));
                    
                    $dispatchedCount++;
                    $delay += $this->delaySeconds; // Add delay for next job
                }

                Log::info('Dispatched batch of article sync jobs', [
                    'batch_count' => $articles->count(),
                    'total_dispatched' => $dispatchedCount,
                    'current_delay' => $delay
                ]);
            });

        $estimatedMinutes = round($delay / 60);
        
        Log::info('Completed dispatching article extra fields sync jobs', [
            'total_dispatched' => $dispatchedCount,
            'total_delay_seconds' => $delay,
            'estimated_completion_minutes' => $estimatedMinutes
        ]);
    }
}


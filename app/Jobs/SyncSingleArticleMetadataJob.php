<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Robaws\RobawsArticleProvider;
use Illuminate\Support\Facades\Log;

class SyncSingleArticleMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $articleId
    ) {
        $this->onQueue('article-metadata');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $provider = app(RobawsArticleProvider::class);
        
        try {
            Log::info('Syncing metadata for single article', [
                'article_id' => $this->articleId
            ]);
            
            // Sync article metadata (shipping_line, service_type, etc.)
            $provider->syncArticleMetadata($this->articleId);
            
            // Sync composite items (child articles/surcharges)
            $provider->syncCompositeItems($this->articleId);
            
            Log::info('Successfully synced metadata for article', [
                'article_id' => $this->articleId
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to sync metadata for article', [
                'article_id' => $this->articleId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
}

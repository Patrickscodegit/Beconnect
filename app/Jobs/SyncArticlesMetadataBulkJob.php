<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Robaws\RobawsArticleProvider;
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
    public $timeout = 3600; // 1 hour for bulk operations

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $articleIds
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $provider = app(RobawsArticleProvider::class);
        
        Log::info('Starting bulk metadata sync', [
            'article_count' => count($this->articleIds)
        ]);
        
        $successCount = 0;
        $failCount = 0;
        
        foreach ($this->articleIds as $articleId) {
            try {
                // Sync article metadata (shipping_line, service_type, etc.)
                $provider->syncArticleMetadata($articleId);
                
                // Sync composite items (child articles/surcharges)
                $provider->syncCompositeItems($articleId);
                
                $successCount++;
                
                Log::debug('Synced metadata for article', [
                    'article_id' => $articleId,
                    'progress' => "{$successCount}/" . count($this->articleIds)
                ]);
                
            } catch (\Exception $e) {
                $failCount++;
                
                Log::error('Failed to sync metadata for article in bulk', [
                    'article_id' => $articleId,
                    'error' => $e->getMessage()
                ]);
                
                // Continue with next article even if one fails
                continue;
            }
            
            // Rate limiting: wait 500ms between requests to respect Robaws API limits
            usleep(500000);
        }
        
        Log::info('Completed bulk metadata sync', [
            'total' => count($this->articleIds),
            'success' => $successCount,
            'failed' => $failCount
        ]);
    }
}

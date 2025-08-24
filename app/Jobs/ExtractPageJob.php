<?php

namespace App\Jobs;

use App\Services\AiRouter;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExtractPageJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // 2 minutes per page

    public function __construct(
        public int $documentId,
        public int $pageNumber,
        public string $pageContent,
        public array $schema
    ) {
        $this->onQueue('ai-extraction');
    }

    public function handle(): void
    {
        if ($this->batch()->cancelled()) {
            return;
        }

        Log::info('Processing page extraction', [
            'document_id' => $this->documentId,
            'page_number' => $this->pageNumber,
        ]);

        try {
            $aiRouter = app(AiRouter::class);
            
            $result = $aiRouter->extract($this->pageContent, $this->schema, [
                'cheap' => true, // Use cheap model for individual pages
                'reasoning' => false,
            ]);

            // Store page result in cache for later merging
            $cacheKey = "page_extraction:{$this->documentId}:{$this->pageNumber}";
            Cache::put($cacheKey, $result, 3600); // 1 hour TTL

            Log::info('Page extraction completed', [
                'document_id' => $this->documentId,
                'page_number' => $this->pageNumber,
                'fields_extracted' => count($result),
            ]);

        } catch (\Exception $e) {
            Log::error('Page extraction failed', [
                'document_id' => $this->documentId,
                'page_number' => $this->pageNumber,
                'error' => $e->getMessage(),
            ]);

            // Store error result
            $cacheKey = "page_extraction:{$this->documentId}:{$this->pageNumber}";
            Cache::put($cacheKey, ['error' => $e->getMessage()], 3600);

            // Don't fail the batch for individual page failures
        }
    }
}

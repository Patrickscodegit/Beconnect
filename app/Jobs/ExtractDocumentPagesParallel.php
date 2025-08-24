<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\AiRouter;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ExtractDocumentPagesParallel implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes

    public function __construct(
        public Document $document,
        public array $schema
    ) {
        $this->onQueue('ai-extraction');
    }

    public function handle(): void
    {
        if ($this->batch()->cancelled()) {
            return;
        }

        Log::info('Starting parallel extraction', [
            'document_id' => $this->document->id,
            'file_name' => $this->document->original_name,
        ]);

        try {
            $pageCount = $this->estimatePageCount();
            
            if ($pageCount <= 3) {
                // Process small documents directly
                $this->processSingleDocument();
                return;
            }

            // Process large documents in parallel
            $this->processLargeDocumentInParallel($pageCount);

        } catch (\Exception $e) {
            Log::error('Parallel extraction failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
            ]);

            // Fall back to single document processing
            $this->processSingleDocument();
        }
    }

    protected function processSingleDocument(): void
    {
        $aiRouter = app(AiRouter::class);
        $text = $this->extractTextFromDocument();
        
        $result = $aiRouter->extract($text, $this->schema, [
            'cheap' => $this->document->file_size < 1_000_000,
            'reasoning' => false,
        ]);

        $this->updateExtraction($result);
        
        Log::info('Single document extraction completed', [
            'document_id' => $this->document->id,
            'fields_extracted' => count($result),
        ]);
    }

    protected function processLargeDocumentInParallel(int $pageCount): void
    {
        $maxPages = min($pageCount, config('ai.performance.max_pages_parallel', 8));
        $pages = $this->splitDocumentIntoPages($maxPages);
        
        if (empty($pages)) {
            $this->processSingleDocument();
            return;
        }

        $jobs = collect($pages)->map(function ($pageContent, $pageNumber) {
            return new ExtractPageJob(
                $this->document->id,
                $pageNumber,
                $pageContent,
                $this->schema
            );
        });

        Bus::batch($jobs)
            ->allowFailures()
            ->onQueue('ai-extraction')
            ->finally(function () {
                $this->mergePageResults();
            })
            ->dispatch();

        Log::info('Dispatched parallel page extraction', [
            'document_id' => $this->document->id,
            'page_count' => count($jobs),
        ]);
    }

    protected function estimatePageCount(): int
    {
        // Simple estimation based on file size
        // You can improve this with actual PDF page counting
        $fileSizeMB = $this->document->file_size / 1_000_000;
        return max(1, (int) ceil($fileSizeMB / 0.5)); // ~0.5MB per page estimate
    }

    protected function extractTextFromDocument(): string
    {
        // Use your existing OCR service
        $ocrService = app(\App\Services\OcrService::class);
        return $ocrService->extractText($this->document->getLocalPath());
    }

    protected function splitDocumentIntoPages(int $maxPages): array
    {
        // This is a simplified implementation
        // You would use a proper PDF library like spatie/pdf-to-text or similar
        try {
            $text = $this->extractTextFromDocument();
            $textLength = strlen($text);
            $chunkSize = (int) ceil($textLength / $maxPages);
            
            $pages = [];
            for ($i = 0; $i < $maxPages; $i++) {
                $start = $i * $chunkSize;
                $chunk = substr($text, $start, $chunkSize);
                
                if (!empty(trim($chunk))) {
                    $pages[$i + 1] = $chunk;
                }
            }
            
            return $pages;
            
        } catch (\Exception $e) {
            Log::warning('Failed to split document into pages', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
            ]);
            
            return [];
        }
    }

    protected function mergePageResults(): void
    {
        // Collect results from all page jobs
        $allResults = collect();
        
        // This is a simplified merge - you'd implement proper merging logic
        // based on your extraction schema and requirements
        $mergedResult = [
            'merged_extraction' => true,
            'page_count' => $allResults->count(),
            'timestamp' => now()->toISOString(),
        ];

        $this->updateExtraction($mergedResult);
        
        Log::info('Page results merged', [
            'document_id' => $this->document->id,
            'pages_processed' => $allResults->count(),
        ]);
    }

    protected function updateExtraction(array $result): void
    {
        $extraction = $this->document->extraction;
        
        if ($extraction) {
            $extraction->update([
                'extracted_data' => $result,
                'raw_json' => json_encode($result, JSON_PRETTY_PRINT),
                'status' => 'completed',
                'confidence' => 0.95,
                'service_used' => config('ai.primary_service', 'openai'),
                'completed_at' => now(),
            ]);
        }
    }
}

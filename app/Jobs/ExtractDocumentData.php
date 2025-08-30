<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Extraction;
use App\Services\Extraction\ExtractionPipeline;
use App\Services\Extraction\IntegrationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ExtractDocumentData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Document $document
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        ExtractionPipeline $pipeline, 
        IntegrationDispatcher $dispatcher
    ): void
    {
        $startTime = microtime(true);

        try {
            Log::info('Starting document extraction', [
                'document_id' => $this->document->id,
                'filename' => $this->document->filename
            ]);

            // Use database transaction to prevent race conditions
            $extraction = DB::transaction(function () {
                // Lock the document record to prevent concurrent extractions
                $this->document->lockForUpdate();

                // Check if extraction already exists
                $existingExtraction = $this->document->extractions()
                    ->whereIn('status', ['processing', 'completed'])
                    ->first();
                
                if ($existingExtraction) {
                    Log::info('Extraction already exists, skipping', [
                        'document_id' => $this->document->id,
                        'existing_extraction_id' => $existingExtraction->id,
                        'existing_status' => $existingExtraction->status
                    ]);
                    return $existingExtraction;
                }

                // Create new extraction record
                return $this->document->extractions()->create([
                    'intake_id' => $this->document->intake_id,
                    'status' => 'processing',
                    'confidence' => 0.0,
                    'service_used' => 'extraction_pipeline',
                    'extracted_data' => [],
                    'raw_json' => '{}',
                ]);
            });

            // If extraction already existed and is completed, don't reprocess
            if ($extraction->status === 'completed') {
                Log::info('Using existing completed extraction', [
                    'extraction_id' => $extraction->id
                ]);
                return;
            }

            // Process document through extraction pipeline
            $result = $pipeline->process($this->document);

            // Update extraction record with results
            $extractionData = [
                'status' => $result->isSuccessful() ? 'completed' : 'failed',
                'confidence' => $result->getConfidence(),
                'service_used' => $result->getStrategy(),
                'analysis_type' => $result->getMetadata('pipeline.strategy_used', 'unknown'),
                'raw_json' => json_encode($result->getData())
            ];

            if ($result->isSuccessful()) {
                $transformedData = $result->getData();
                
                Log::info('Extraction successful', [
                    'extraction_id' => $extraction->id,
                    'confidence' => $result->getConfidence(),
                    'strategy' => $result->getStrategy()
                ]);
                
                $extractionData['extracted_data'] = [
                    'document_data' => $result->getDocumentData(),
                    'ai_enhanced_data' => $result->getAiEnhancedData(),
                    'data_attribution' => $result->getDataAttribution(),
                    'metadata' => $result->getAllMetadata()
                ];

                // Update document with extraction results
                $this->document->update([
                    'extraction_status' => 'completed',
                    'extraction_data' => json_encode($result->getData()),
                    'extraction_confidence' => $result->getConfidence(),
                    'extraction_service' => $result->getStrategy(),
                    'extracted_at' => now(),
                    'ai_extracted_data' => $transformedData,
                    'ai_processing_status' => 'completed'
                ]);

                // Dispatch to integrations (this will create the quotation)
                try {
                    $integrationResults = $dispatcher->dispatch($this->document, $result);
                    
                    Log::info('Integration dispatch completed', [
                        'document_id' => $this->document->id,
                        'extraction_id' => $extraction->id,
                        'integration_count' => count($integrationResults)
                    ]);
                    
                    // The ExtractionObserver will handle file upload when robaws_quotation_id is set
                    
                } catch (\Exception $e) {
                    Log::error('Integration dispatch failed', [
                        'document_id' => $this->document->id,
                        'extraction_id' => $extraction->id,
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                $extractionData['extracted_data'] = [
                    'error' => $result->getErrorMessage(),
                    'metadata' => $result->getAllMetadata()
                ];

                $this->document->update([
                    'extraction_status' => 'failed',
                    'ai_processing_status' => 'failed'
                ]);
            }

            $extraction->update($extractionData);

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Document extraction completed', [
                'document_id' => $this->document->id,
                'extraction_id' => $extraction->id,
                'success' => $result->isSuccessful(),
                'processing_time_ms' => $processingTime
            ]);

        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('Document extraction failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime
            ]);

            if (isset($extraction) && $extraction->status === 'processing') {
                $extraction->update([
                    'status' => 'failed',
                    'extracted_data' => ['error' => $e->getMessage()],
                    'analysis_type' => 'failed',
                ]);
            }

            $this->document->update([
                'ai_processing_status' => 'failed'
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Extraction job permanently failed', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage()
        ]);

        $this->document->extractions()
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'extracted_data' => ['error' => 'Job failed: ' . $exception->getMessage()],
            ]);

        $this->document->update([
            'ai_processing_status' => 'failed'
        ]);
    }
}

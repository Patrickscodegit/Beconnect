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
    public function handle(ExtractionPipeline $pipeline, IntegrationDispatcher $dispatcher): void
    {
        $startTime = microtime(true);

        try {
            Log::info('Starting document extraction with new pipeline', [
                'document_id' => $this->document->id,
                'filename' => $this->document->filename,
                'mime_type' => $this->document->mime_type
            ]);

            // Create extraction record
            $extraction = $this->document->extractions()->create([
                'intake_id' => $this->document->intake_id,
                'status' => 'processing',
                'confidence' => 0.0,
                'service_used' => 'extraction_pipeline',
                'extracted_data' => [],
                'raw_json' => '{}',
            ]);

            // Process document through extraction pipeline
            $result = $pipeline->process($this->document);

            // Update extraction record with results
            $extractionData = [
                'status' => $result->isSuccessful() ? 'completed' : 'failed',
                'confidence' => $result->getConfidence(),
                'service_used' => $result->getStrategy(),
                'analysis_type' => $result->getMetadata('pipeline.strategy_used', 'unknown'),
                'raw_json' => json_encode($result->getData()) // Store the transformed data directly
            ];

            if ($result->isSuccessful()) {
                // Store the complete transformed data (includes JSON field and flattened vehicle data)
                $transformedData = $result->getData();
                
                // Log what we're storing
                Log::info('Storing extraction data', [
                    'extraction_id' => $extraction->id,
                    'has_json_field' => isset($transformedData['JSON']),
                    'field_count' => count($transformedData),
                    'sample_fields' => array_slice(array_keys($transformedData), 0, 10),
                    'json_field_size' => strlen($transformedData['JSON'] ?? '')
                ]);
                
                // Separate document data from AI-enhanced data for backward compatibility
                $extractionData['extracted_data'] = [
                    'document_data' => $result->getDocumentData(),
                    'ai_enhanced_data' => $result->getAiEnhancedData(),
                    'data_attribution' => $result->getDataAttribution(),
                    'metadata' => $result->getAllMetadata()
                ];

                // Store AI extracted data on document (use transformed data)
                $this->document->update([
                    'ai_extracted_data' => $transformedData, // Use transformed data with JSON field
                    'ai_processing_status' => 'completed'
                ]);
            } else {
                $extractionData['extracted_data'] = [
                    'error' => $result->getErrorMessage(),
                    'metadata' => $result->getAllMetadata()
                ];

                $this->document->update([
                    'ai_processing_status' => 'failed'
                ]);
            }

            $extraction->update($extractionData);

            // Dispatch to integrations if extraction was successful
            if ($result->isSuccessful()) {
                try {
                    $integrationResults = $dispatcher->dispatch($this->document, $result);
                    
                    Log::info('Integration dispatch completed', [
                        'document_id' => $this->document->id,
                        'extraction_id' => $extraction->id,
                        'integration_results' => array_keys($integrationResults)
                    ]);
                } catch (\Exception $e) {
                    Log::error('Integration dispatch failed', [
                        'document_id' => $this->document->id,
                        'extraction_id' => $extraction->id,
                        'error' => $e->getMessage()
                    ]);
                    // Don't fail the job for integration errors
                }
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Document extraction completed', [
                'document_id' => $this->document->id,
                'extraction_id' => $extraction->id,
                'success' => $result->isSuccessful(),
                'confidence' => $result->getConfidence(),
                'strategy' => $result->getStrategy(),
                'processing_time_ms' => $processingTime,
                'document_fields' => count($result->getDocumentData()),
                'ai_enhanced_fields' => count($result->getAiEnhancedData())
            ]);

        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('Document extraction failed', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime,
                'trace' => $e->getTraceAsString()
            ]);

            // Update extraction status if it exists
            if (isset($extraction)) {
                $extraction->update([
                    'status' => 'failed',
                    'extracted_data' => [
                        'error' => $e->getMessage(),
                        'processing_time_ms' => $processingTime
                    ],
                    'analysis_type' => 'failed',
                ]);
            }

            // Update document status
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

        // Update document status
        $this->document->update([
            'ai_processing_status' => 'failed'
        ]);
    }
}

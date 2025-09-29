<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateRobawsOfferJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // 2 minutes timeout for API calls
    public $tries = 3; // Retry up to 3 times

    public function __construct(
        private Document $document
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EnhancedRobawsIntegrationService $integrationService): void
    {
        Log::info('Starting Robaws offer creation', [
            'document_id' => $this->document->id,
            'filename' => $this->document->filename,
            'current_quotation_id' => $this->document->robaws_quotation_id
        ]);

        try {
            // Check if offer already exists
            if ($this->document->robaws_quotation_id) {
                Log::info('Robaws offer already exists, skipping creation', [
                    'document_id' => $this->document->id,
                    'existing_quotation_id' => $this->document->robaws_quotation_id
                ]);
                return;
            }

            // Ensure document has been processed for Robaws integration
            if (!$this->document->robaws_quotation_data) {
                Log::info('Processing document for Robaws integration first', [
                    'document_id' => $this->document->id
                ]);
                
                // Check if document has extraction data, with retry logic
                $maxRetries = 3;
                $retryDelay = 2; // seconds
                
                Log::info('Checking for extraction data', [
                    'document_id' => $this->document->id,
                    'document_extraction_status' => $this->document->extraction_status,
                    'has_extraction_data' => !empty($this->document->extraction_data),
                    'intake_id' => $this->document->intake_id
                ]);
                
                for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                    $this->document->refresh();
                    
                    Log::info('Extraction data check attempt', [
                        'document_id' => $this->document->id,
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'extraction_status' => $this->document->extraction_status,
                        'has_extraction_data' => !empty($this->document->extraction_data),
                        'extraction_data_size' => $this->document->extraction_data ? (is_string($this->document->extraction_data) ? strlen($this->document->extraction_data) : count($this->document->extraction_data)) : 0
                    ]);
                    
                    if ($this->document->extraction_data) {
                        Log::info('Extraction data found', [
                            'document_id' => $this->document->id,
                            'attempt' => $attempt,
                            'extraction_data_size' => is_string($this->document->extraction_data) ? strlen($this->document->extraction_data) : count($this->document->extraction_data)
                        ]);
                        break;
                    }
                    
                    if ($attempt < $maxRetries) {
                        Log::info('Waiting for extraction data to be available', [
                            'document_id' => $this->document->id,
                            'attempt' => $attempt,
                            'max_retries' => $maxRetries,
                            'retry_delay' => $retryDelay
                        ]);
                        sleep($retryDelay);
                    } else {
                        Log::error('No extraction data found after all attempts', [
                            'document_id' => $this->document->id,
                            'max_retries' => $maxRetries,
                            'final_extraction_status' => $this->document->extraction_status,
                            'intake_id' => $this->document->intake_id,
                            'document_filename' => $this->document->filename
                        ]);
                        throw new \RuntimeException('No extraction data found for document after ' . $maxRetries . ' attempts');
                    }
                }

                $extractedData = is_array($this->document->extraction_data) 
                    ? $this->document->extraction_data 
                    : json_decode($this->document->extraction_data, true);

                $integrationService->processDocument($this->document, $extractedData);
                $this->document->refresh();
            }

            // Create the offer
            $result = $integrationService->createOfferFromDocument($this->document);
            
            if (!isset($result['id'])) {
                throw new \RuntimeException('Robaws offer creation failed: missing id');
            }

            Log::info('Robaws offer created successfully', [
                'document_id' => $this->document->id,
                'offer_id' => $result['id'],
                'filename' => $this->document->filename
            ]);

        } catch (\Exception $e) {
            Log::error('Robaws offer creation failed', [
                'document_id' => $this->document->id,
                'filename' => $this->document->filename,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update document status to indicate failure
            $this->document->update([
                'robaws_sync_status' => 'error',
                'robaws_last_sync_attempt' => now(),
                'robaws_last_sync_error' => $e->getMessage()
            ]);

            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CreateRobawsOfferJob failed permanently', [
            'document_id' => $this->document->id,
            'filename' => $this->document->filename,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Update document status to indicate permanent failure
        $this->document->update([
            'robaws_sync_status' => 'error',
            'robaws_last_sync_attempt' => now(),
            'robaws_last_sync_error' => 'Job failed after ' . $this->attempts() . ' attempts: ' . $exception->getMessage()
        ]);
    }
}

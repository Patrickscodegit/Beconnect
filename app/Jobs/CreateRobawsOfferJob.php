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
                
                // Check if document has extraction data
                if (!$this->document->extraction_data) {
                    throw new \RuntimeException('No extraction data found for document');
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

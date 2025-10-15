<?php

namespace App\Jobs;

use App\Models\Intake;
use App\Models\Document;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Jobs\CreateRobawsClientJob;
use App\Jobs\CreateRobawsOfferJob;
use App\Jobs\ProcessFileUploadJob;
use App\Jobs\UpdateIntakeStatusJob;
use App\Jobs\ExtractDocumentDataJob;
use App\Services\ExtractionService;

class IntakeOrchestratorJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private Intake $intake
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting intake orchestration', [
            'intake_id' => $this->intake->id,
            'status' => $this->intake->status
        ]);

        try {
            // Get all documents for this intake
            $documents = $this->intake->documents;
            
            // If no documents exist, create them from intake files
            if ($documents->isEmpty()) {
                Log::info('No documents found, creating from intake files', [
                    'intake_id' => $this->intake->id
                ]);
                
                $files = $this->intake->files;
                if ($files && $files->isNotEmpty()) {
                    foreach ($files as $file) {
                    $document = \App\Models\Document::create([
                        'intake_id' => $this->intake->id,
                        'filename' => $file->filename,
                        'file_path' => $file->storage_path,
                        'mime_type' => $file->mime_type,
                        'file_size' => $file->file_size,
                        'storage_disk' => $file->storage_disk,
                        'original_filename' => $file->original_filename,
                        'processing_status' => 'pending',
                        'status' => 'pending',
                    ]);
                    
                    Log::info('Created document from intake file', [
                        'intake_id' => $this->intake->id,
                        'document_id' => $document->id,
                        'filename' => $file->filename
                    ]);
                }
                } else {
                    Log::warning('No intake files found for document creation', [
                        'intake_id' => $this->intake->id
                    ]);
                }
                
                // Refresh documents collection
                $documents = $this->intake->fresh()->documents;
            }

            // Prepare jobs for sequential processing with proper dependencies
            $jobs = [];
            
            // Step 1: Add extraction jobs for each document first
            foreach ($documents as $document) {
                Log::info('Adding extraction job for document', [
                    'intake_id' => $this->intake->id,
                    'document_id' => $document->id,
                    'filename' => $document->filename,
                    'mime_type' => $document->mime_type,
                    'current_extraction_status' => $document->extraction_status
                ]);
                $jobs[] = new ExtractDocumentDataJob($document);
            }
            
            // Step 2: Add client creation job after extraction (so it has updated contact data)
            if (!$this->intake->robaws_client_id) {
                $jobs[] = new CreateRobawsClientJob($this->intake->id, $this->getContactData());
            }
            
            // Step 3: Add offer creation - ENHANCED for multi-document support
            if ($this->intake->is_multi_document && !$this->intake->robaws_offer_id) {
                // Multi-document intake: Create ONE offer using aggregated data
                Log::info('Multi-document intake detected - will create single aggregated offer', [
                    'intake_id' => $this->intake->id,
                    'total_documents' => $this->intake->total_documents,
                    'documents_count' => $documents->count()
                ]);
                
                // Aggregate extraction data from all documents
                $aggregationService = app(\App\Services\IntakeAggregationService::class);
                $aggregatedData = $aggregationService->aggregateExtractionData($this->intake);
                
                Log::info('Extraction data aggregated', [
                    'intake_id' => $this->intake->id,
                    'has_contact' => !empty($aggregatedData['contact']),
                    'has_vehicle' => !empty($aggregatedData['vehicle']),
                    'sources_merged' => count($aggregatedData['metadata']['sources'] ?? [])
                ]);
                
                // Create ONE offer using aggregated data
                try {
                    $offerId = $aggregationService->createSingleOffer($this->intake);
                    
                    Log::info('Single offer created for multi-document intake', [
                        'intake_id' => $this->intake->id,
                        'offer_id' => $offerId,
                        'documents_linked' => $documents->count()
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to create aggregated offer', [
                        'intake_id' => $this->intake->id,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            } else {
                // Single document intake: Use existing per-document offer creation (backward compatible)
                foreach ($documents as $document) {
                    // Create offer if no quotation ID
                    if (!$document->robaws_quotation_id) {
                        Log::info('Adding offer creation job for document', [
                            'intake_id' => $this->intake->id,
                            'document_id' => $document->id,
                            'filename' => $document->filename,
                            'current_quotation_id' => $document->robaws_quotation_id,
                            'current_sync_status' => $document->robaws_sync_status
                        ]);
                        $jobs[] = new CreateRobawsOfferJob($document);
                    } else {
                        Log::info('Skipping offer creation - quotation already exists', [
                            'intake_id' => $this->intake->id,
                            'document_id' => $document->id,
                            'existing_quotation_id' => $document->robaws_quotation_id
                        ]);
                    }
                }
            }
            
            // Step 4: Add upload jobs after offer creation
            foreach ($documents as $document) {
                // Upload file if quotation exists and not uploaded
                if ($document->robaws_quotation_id && $document->upload_status !== 'uploaded') {
                    $jobs[] = new ProcessFileUploadJob($document);
                }
            }

            // Step 5: Add status update job
            $jobs[] = new UpdateIntakeStatusJob($this->intake->id, 'processing_complete');

            if (empty($jobs)) {
                Log::info('No jobs needed for intake orchestration', [
                    'intake_id' => $this->intake->id,
                    'documents_count' => $documents->count()
                ]);
                
                // Update status to completed if nothing to do
                $this->intake->update(['status' => 'completed']);
                return;
            }

            // Execute jobs sequentially to ensure proper order
            // Run jobs one by one instead of using Bus::chain() to avoid timing issues
            foreach ($jobs as $index => $job) {
                try {
                    Log::info('Executing job in sequence', [
                        'intake_id' => $this->intake->id,
                        'job_index' => $index + 1,
                        'total_jobs' => count($jobs),
                        'job_class' => get_class($job)
                    ]);
                    
                    // Execute the job directly with proper dependencies
                    if ($job instanceof ExtractDocumentDataJob) {
                        // Use reflection to access the private document property
                        $reflection = new \ReflectionClass($job);
                        $documentProperty = $reflection->getProperty('document');
                        $documentProperty->setAccessible(true);
                        $document = $documentProperty->getValue($job);
                        
                        Log::info('Executing ExtractDocumentDataJob', [
                            'intake_id' => $this->intake->id,
                            'document_id' => $document->id,
                            'filename' => $document->filename
                        ]);
                        $job->handle(app(ExtractionService::class));
                    } elseif ($job instanceof CreateRobawsClientJob) {
                        Log::info('Executing CreateRobawsClientJob', [
                            'intake_id' => $this->intake->id,
                            'intake_client_id' => $job->intakeId
                        ]);
                        $job->handle(app(\App\Services\Export\Clients\RobawsApiClient::class));
                    } elseif ($job instanceof CreateRobawsOfferJob) {
                        // Use reflection to access the private document property
                        $reflection = new \ReflectionClass($job);
                        $documentProperty = $reflection->getProperty('document');
                        $documentProperty->setAccessible(true);
                        $document = $documentProperty->getValue($job);
                        
                        Log::info('Executing CreateRobawsOfferJob', [
                            'intake_id' => $this->intake->id,
                            'document_id' => $document->id,
                            'filename' => $document->filename
                        ]);
                        $job->handle(app(\App\Services\RobawsIntegration\EnhancedRobawsIntegrationService::class));
                    } elseif ($job instanceof ProcessFileUploadJob) {
                        // Use reflection to access the private document property
                        $reflection = new \ReflectionClass($job);
                        $documentProperty = $reflection->getProperty('document');
                        $documentProperty->setAccessible(true);
                        $document = $documentProperty->getValue($job);
                        
                        Log::info('Executing ProcessFileUploadJob', [
                            'intake_id' => $this->intake->id,
                            'document_id' => $document->id
                        ]);
                        $job->handle(app(\App\Services\MultiDocumentUploadService::class));
                    } else {
                        Log::info('Executing generic job', [
                            'intake_id' => $this->intake->id,
                            'job_class' => get_class($job)
                        ]);
                        $job->handle();
                    }
                    
                    Log::info('Job completed successfully', [
                        'intake_id' => $this->intake->id,
                        'job_index' => $index + 1,
                        'job_class' => get_class($job)
                    ]);
                    
                } catch (\Exception $e) {
                    Log::error('Job failed in sequence', [
                        'intake_id' => $this->intake->id,
                        'job_index' => $index + 1,
                        'job_class' => get_class($job),
                        'error' => $e->getMessage()
                    ]);
                    
                    // Update intake status to failed
                    $this->intake->update([
                        'status' => 'processing_failed',
                        'last_export_error' => 'Job execution failed: ' . $e->getMessage(),
                        'last_export_error_at' => now()
                    ]);
                    
                    throw $e;
                }
            }

            Log::info('Intake orchestration completed successfully', [
                'intake_id' => $this->intake->id,
                'jobs_executed' => count($jobs)
            ]);

            // Clear any previous error messages on successful completion
            $this->intake->update([
                'last_export_error' => null,
                'last_export_error_at' => null
            ]);

        } catch (\Exception $e) {
            Log::error('Intake orchestration failed with exception', [
                'intake_id' => $this->intake->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update intake status to failed
            $this->intake->update([
                'status' => 'processing_failed',
                'last_export_error' => 'Orchestration exception: ' . $e->getMessage(),
                'last_export_error_at' => now()
            ]);

            throw $e;
        }
    }

    /**
     * Get contact data from intake
     */
    private function getContactData(): array
    {
        return [
            'name' => $this->intake->customer_name,
            'email' => $this->intake->contact_email,
            'phone' => $this->intake->contact_phone,
        ];
    }
}
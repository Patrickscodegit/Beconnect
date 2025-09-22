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

            // Prepare jobs for batch processing
            $jobs = [];
            
            // Add extraction and processing jobs for each document first
            foreach ($documents as $document) {
                // First extract data and process document
                $jobs[] = new ExtractDocumentDataJob($document);
                
                // Then create offer if no quotation ID
                if (!$document->robaws_quotation_id) {
                    $jobs[] = new CreateRobawsOfferJob($document);
                }
                
                // Finally upload file if quotation exists and not uploaded
                if ($document->robaws_quotation_id && $document->upload_status !== 'uploaded') {
                    $jobs[] = new ProcessFileUploadJob($document);
                }
            }
            
            // Add client creation job after extraction (so it has updated contact data)
            if (!$this->intake->robaws_client_id) {
                $jobs[] = new CreateRobawsClientJob($this->intake->id, $this->getContactData());
            }

            // Add status update job
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

            // Dispatch batch jobs
                $batch = Bus::batch($jobs)
                    ->name("Intake Orchestration - {$this->intake->id}")
                    ->onQueue('default')
                    ->dispatch();

            Log::info('Intake orchestration batch dispatched', [
                'intake_id' => $this->intake->id,
                'jobs_count' => count($jobs)
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
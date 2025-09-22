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
            
            if ($documents->isEmpty()) {
                Log::warning('No documents found for intake orchestration', [
                    'intake_id' => $this->intake->id
                ]);
                return;
            }

            // Prepare jobs for batch processing
            $jobs = [];
            
            // Add client creation job if needed
            if ($this->intake->status === 'client_pending' && !$this->intake->robaws_client_id) {
                $jobs[] = new CreateRobawsClientJob($this->intake->id, $this->getContactData());
            }

            // Add offer creation and file upload jobs for each document
            foreach ($documents as $document) {
                if (!$document->robaws_quotation_id) {
                    $jobs[] = new CreateRobawsOfferJob($document);
                }
                
                if ($document->robaws_quotation_id && $document->upload_status !== 'uploaded') {
                    $jobs[] = new ProcessFileUploadJob($document);
                }
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
            Bus::batch($jobs)
                ->name("Intake Orchestration - {$this->intake->id}")
                ->onQueue('default')
                ->then(function (Batch $batch) {
                    Log::info('Intake orchestration completed successfully', [
                        'intake_id' => $this->intake->id,
                        'batch_id' => $batch->id
                    ]);
                })
                ->catch(function (Batch $batch, \Throwable $e) {
                    Log::error('Intake orchestration failed', [
                        'intake_id' => $this->intake->id,
                        'batch_id' => $batch->id,
                        'error' => $e->getMessage()
                    ]);
                    
                    // Update intake status to failed
                    $this->intake->update([
                        'status' => 'processing_failed',
                        'last_export_error' => 'Orchestration failed: ' . $e->getMessage(),
                        'last_export_error_at' => now()
                    ]);
                })
                ->finally(function (Batch $batch) {
                    Log::info('Intake orchestration batch finished', [
                        'intake_id' => $this->intake->id,
                        'batch_id' => $batch->id,
                        'total_jobs' => $batch->totalJobs,
                        'processed_jobs' => $batch->processedJobs(),
                        'failed_jobs' => $batch->failedJobs
                    ]);
                })
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
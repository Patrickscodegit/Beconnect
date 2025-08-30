<?php

namespace App\Jobs;

use App\Models\Intake;
use App\Services\RobawsIntegrationService;
use App\Exceptions\RobawsException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExportIntakeToRobaws implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $intake;

    /**
     * Create a new job instance.
     */
    public function __construct(Intake $intake)
    {
        $this->intake = $intake;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting Robaws export for intake', [
            'intake_id' => $this->intake->id
        ]);

        try {
            // Validate Robaws configuration first
            $this->validateRobawsConfig();

            // Get all documents with completed extractions
            $documents = $this->intake->documents()
                ->whereHas('extractions', function ($query) {
                    $query->where('status', 'completed')
                          ->whereNotNull('extracted_data');
                })
                ->get();

            if ($documents->isEmpty()) {
                Log::warning('No documents with completed extractions found', [
                    'intake_id' => $this->intake->id
                ]);
                
                $this->intake->update(['status' => 'failed']);
                return;
            }

            $successCount = 0;
            $failureCount = 0;
            $errors = [];

            // Export each document to Robaws
            foreach ($documents as $document) {
                try {
                    // Get the latest completed extraction
                    $extraction = $document->extractions()
                        ->where('status', 'completed')
                        ->whereNotNull('extracted_data')
                        ->latest()
                        ->first();

                    if (!$extraction) {
                        Log::warning('No valid extraction found for document', [
                            'document_id' => $document->id
                        ]);
                        $failureCount++;
                        continue;
                    }

                    // Skip if already exported
                    if ($extraction->robaws_quotation_id) {
                        Log::info('Document already exported to Robaws', [
                            'document_id' => $document->id,
                            'quotation_id' => $extraction->robaws_quotation_id
                        ]);
                        $successCount++;
                        continue;
                    }

                    // Validate extracted data
                    $extractedData = is_string($extraction->extracted_data) 
                        ? json_decode($extraction->extracted_data, true) 
                        : $extraction->extracted_data;

                    if (empty($extractedData)) {
                        Log::error('Empty extraction data', [
                            'document_id' => $document->id,
                            'extraction_id' => $extraction->id
                        ]);
                        $failureCount++;
                        $errors[] = "Document {$document->id}: Empty extraction data";
                        continue;
                    }

                    // Log the data being sent
                    Log::info('Sending data to Robaws', [
                        'document_id' => $document->id,
                        'has_extracted_data' => !empty($extractedData),
                        'extraction_keys' => array_keys($extractedData)
                    ]);

                    // Create offer in Robaws using the service
                    $robawsService = app(RobawsIntegrationService::class);
                    $result = $robawsService->createOfferFromDocument($document);
                    
                    if ($result && isset($result['id'])) {
                        // Update extraction with Robaws quotation ID
                        $extraction->update([
                            'robaws_quotation_id' => $result['id']
                        ]);
                        
                        // Update document status if columns exist
                        $updateData = ['robaws_quotation_id' => $result['id']];
                        
                        if (\Schema::hasColumn('documents', 'robaws_upload_status')) {
                            $updateData['robaws_upload_status'] = 'pending'; // Will be updated by upload job
                        }
                        
                        if (\Schema::hasColumn('documents', 'robaws_uploaded_at')) {
                            $updateData['robaws_uploaded_at'] = now();
                        }
                        
                        $document->update($updateData);
                        
                        $successCount++;
                        
                        Log::info('Document exported to Robaws successfully', [
                            'intake_id' => $this->intake->id,
                            'document_id' => $document->id,
                            'robaws_quotation_id' => $result['id'],
                            'note' => 'File upload will be handled automatically by ExtractionObserver'
                        ]);

                        // NOTE: File upload is handled automatically by ExtractionObserver
                        // When robaws_quotation_id is updated, the observer dispatches UploadDocumentToRobaws
                        // This prevents duplicate uploads
                    } else {
                        $failureCount++;
                        $error = "Failed to create quotation - no ID returned";
                        $errors[] = "Document {$document->id}: {$error}";
                        
                        Log::error('Failed to export document to Robaws', [
                            'intake_id' => $this->intake->id,
                            'document_id' => $document->id,
                            'result' => $result
                        ]);
                    }
                    
                } catch (RobawsException $e) {
                    $failureCount++;
                    $errors[] = "Document {$document->id}: Robaws error - {$e->getMessage()}";
                    
                    Log::error('Robaws API error during export', [
                        'intake_id' => $this->intake->id,
                        'document_id' => $document->id,
                        'error' => $e->getMessage()
                    ]);
                } catch (\Exception $e) {
                    $failureCount++;
                    $errors[] = "Document {$document->id}: {$e->getMessage()}";
                    
                    Log::error('Error exporting document to Robaws', [
                        'intake_id' => $this->intake->id,
                        'document_id' => $document->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            // Update intake status based on results
            if ($failureCount === 0 && $successCount > 0) {
                $this->intake->update(['status' => 'completed']);
                Log::info('Intake export completed successfully', [
                    'intake_id' => $this->intake->id,
                    'success_count' => $successCount
                ]);
            } else if ($successCount === 0) {
                $this->intake->update(['status' => 'failed']);
                Log::error('Intake export failed completely', [
                    'intake_id' => $this->intake->id,
                    'failure_count' => $failureCount,
                    'errors' => $errors
                ]);
            } else {
                $this->intake->update(['status' => 'partially_completed']);
                Log::warning('Intake export partially completed', [
                    'intake_id' => $this->intake->id,
                    'success_count' => $successCount,
                    'failure_count' => $failureCount,
                    'errors' => $errors
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Fatal error during intake export', [
                'intake_id' => $this->intake->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->intake->update(['status' => 'failed']);
            throw $e;
        }
    }

    /**
     * Validate Robaws configuration
     */
    private function validateRobawsConfig(): void
    {
        $username = config('services.robaws.username');
        $password = config('services.robaws.password');
        $baseUrl = config('services.robaws.base_url');

        Log::info('Validating Robaws configuration', [
            'has_username' => !empty($username),
            'has_password' => !empty($password),
            'has_base_url' => !empty($baseUrl),
            'base_url' => $baseUrl
        ]);

        if (empty($username) || empty($password)) {
            throw new RobawsException('Robaws credentials are not configured. Please set ROBAWS_USERNAME and ROBAWS_PASSWORD in your .env file.');
        }

        if (empty($baseUrl)) {
            throw new RobawsException('Robaws base URL is not configured. Please set ROBAWS_BASE_URL in your .env file.');
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Export job permanently failed', [
            'intake_id' => $this->intake->id,
            'error' => $exception->getMessage()
        ]);

        $this->intake->update(['status' => 'failed']);
    }
}

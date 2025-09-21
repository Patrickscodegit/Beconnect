<?php

namespace App\Jobs;

use App\Models\Intake;
use App\Services\Robaws\RobawsExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExportIntakeToRobawsJob implements ShouldQueue
{
    use Queueable;

    public $intake;
    
    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;
    
    /**
     * The maximum number of seconds the job can run before timing out.
     */
    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct($intakeId)
    {
        $this->intake = is_numeric($intakeId) ? Intake::findOrFail($intakeId) : $intakeId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting Robaws file upload and status update', ['intake_id' => $this->intake->id]);

        try {
            // Check if offers already exist for this intake's documents
            $documents = $this->intake->documents;
            $hasOffers = $documents->whereNotNull('robaws_quotation_id')->count() > 0;
            
            if (!$hasOffers) {
                Log::warning('No Robaws offers found for intake documents, skipping file upload', [
                    'intake_id' => $this->intake->id,
                    'document_count' => $documents->count()
                ]);
                
                // Update status to completed even if no offers exist
                $this->intake->update(['status' => 'completed']);
                return;
            }
            
            // Upload files to existing offers
            $this->uploadFilesToOffers($documents);
            
            // Update intake status to completed
            $this->intake->update(['status' => 'completed']);
            
            Log::info('Robaws file upload and status update completed', [
                'intake_id' => $this->intake->id,
                'documents_processed' => $documents->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Exception during Robaws file upload', [
                'intake_id' => $this->intake->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Still update status to completed even if file upload fails
            $this->intake->update(['status' => 'completed']);
        }
    }
    
    /**
     * Upload files to existing Robaws offers
     */
    private function uploadFilesToOffers($documents): void
    {
        foreach ($documents as $document) {
            if ($document->robaws_quotation_id) {
                try {
                    Log::info('Uploading file to existing Robaws offer', [
                        'document_id' => $document->id,
                        'offer_id' => $document->robaws_quotation_id,
                        'filename' => $document->filename
                    ]);
                    
                    // Use the existing UploadDocumentToRobaws job
                    \App\Jobs\UploadDocumentToRobaws::dispatch($document, $document->robaws_quotation_id);
                    
                } catch (\Exception $e) {
                    Log::error('Failed to upload file to Robaws offer', [
                        'document_id' => $document->id,
                        'offer_id' => $document->robaws_quotation_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Export with clear error handling and client resolution
     */
    private function exportWithErrorHandling(RobawsExportService $service): array
    {
        $extractionData = $this->intake->extraction_data ?? [];
        
        // Check if we already have a resolved client ID from ProcessIntake
        if (!empty($this->intake->robaws_client_id)) {
            Log::info('Using pre-resolved client ID from ProcessIntake', [
                'intake_id' => $this->intake->id,
                'robaws_client_id' => $this->intake->robaws_client_id
            ]);
            
            // Client already resolved - proceed directly to export
            return $service->exportIntake($this->intake);
        }
        
        // Fallback: Try to resolve client now (for legacy intakes)
        $hasImageOrPdf = $this->intakeHasImageOrPdfFiles();
        
        // For images and PDFs, try to create a client with available data
        if ($hasImageOrPdf) {
            Log::info('Attempting enhanced client resolution for image/PDF intake', [
                'intake_id' => $this->intake->id
            ]);
            
            $clientId = $service->resolveOrCreateClientForImage($this->intake, $extractionData);
            if ($clientId) {
                $this->intake->robaws_client_id = $clientId;
                $this->intake->save();
                return $service->exportIntake($this->intake);
            }
            
            // If we still can't resolve, try with fallback data
            Log::info('Creating fallback client for image/PDF with minimal data', [
                'intake_id' => $this->intake->id
            ]);
            
            $fallbackClientId = $service->createFallbackClient($this->intake, $extractionData);
            if ($fallbackClientId) {
                $this->intake->robaws_client_id = $fallbackClientId;
                $this->intake->save();
                return $service->exportIntake($this->intake);
            }
        }
        
        // Standard client resolution for non-image files
        $clientId = $service->resolveClientId($extractionData);
        if (!$clientId) {
            return [
                'success' => false,
                'error' => 'Could not resolve client in Robaws. Please ensure customer name, email, or phone is valid.'
            ];
        }
        
        // Store resolved client ID and proceed with export
        $this->intake->robaws_client_id = $clientId;
        $this->intake->save();
        
        return $service->exportIntake($this->intake);
    }

    /**
     * Handle export error with clear status and message
     */
    private function handleExportError(string $error): void
    {
        Log::warning('Robaws export failed', [
            'intake_id' => $this->intake->id,
            'error' => $error
        ]);
        
        // Determine status based on error type and file type
        $status = 'export_failed';
        
        // Check if this intake has images or PDFs that should not be blocked for contact
        $hasImageOrPdf = $this->intakeHasImageOrPdfFiles();
        
        if (str_contains(strtolower($error), 'contact') || str_contains(strtolower($error), 'client')) {
            // Only set needs_contact for non-image/PDF files or if explicitly configured
            if (!$hasImageOrPdf || config('intake.processing.require_contact_info', false)) {
                $status = 'needs_contact';
            } else {
                // For images/PDFs, keep as export_failed to allow retry without contact validation
                Log::info('Image/PDF intake export failed but not requiring contact', [
                    'intake_id' => $this->intake->id,
                    'has_image_or_pdf' => $hasImageOrPdf
                ]);
            }
        }
        
        $this->intake->update([
            'status' => $status,
            'last_export_error' => $error,
            'last_export_error_at' => now(),
        ]);
    }

    /**
     * Check if intake has image or PDF files
     */
    private function intakeHasImageOrPdfFiles(): bool
    {
        foreach ($this->intake->files as $file) {
            if (str_starts_with($file->mime_type, 'image/') || $file->mime_type === 'application/pdf') {
                return true;
            }
        }
        return false;
    }
}

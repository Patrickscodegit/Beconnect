<?php

namespace App\Jobs\Intake;

use App\Models\Intake;
use App\Models\IntakeFile;
use App\Models\Document;
use App\Services\Extraction\ExtractionService;
use App\Services\Extraction\Strategies\SimplePdfExtractionStrategy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExtractPdfDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes for PDF extraction (may need OCR)
    public $tries = 3; // Retry up to 3 times

    public function __construct(
        public Intake $intake,
        public IntakeFile $file
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('ExtractPdfDataJob: Starting PDF data extraction', [
            'intake_id' => $this->intake->id,
            'file_id' => $this->file->id,
            'filename' => $this->file->filename,
            'mime_type' => $this->file->mime_type
        ]);

        try {
            // Create Document model for extraction processing
            $document = $this->createDocumentFromIntakeFile();

            // Extract PDF data using specialized strategy
            $extractionService = app(ExtractionService::class);
            $extractionResult = $extractionService->extractFromFile(
                $this->file->storage_path,
                $this->file->storage_disk,
                SimplePdfExtractionStrategy::class
            );

            if ($extractionResult->success) {
                // Update document with extraction results
                $document->update([
                    'extraction_data' => $extractionResult->data,
                    'extraction_status' => 'completed',
                    'extraction_confidence' => $extractionResult->confidence,
                    'status' => 'approved'
                ]);

                Log::info('ExtractPdfDataJob: PDF extraction completed successfully', [
                    'intake_id' => $this->intake->id,
                    'file_id' => $this->file->id,
                    'document_id' => $document->id,
                    'confidence' => $extractionResult->confidence,
                    'data_keys' => array_keys($extractionResult->data ?? [])
                ]);

                // Update intake processed documents count
                $this->updateProcessedDocumentsCount();

            } else {
                throw new \Exception("PDF extraction failed: {$extractionResult->error}");
            }

        } catch (\Exception $e) {
            Log::error('ExtractPdfDataJob: Failed to extract PDF data', [
                'intake_id' => $this->intake->id,
                'file_id' => $this->file->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Create Document model from IntakeFile for extraction processing
     */
    private function createDocumentFromIntakeFile(): Document
    {
        // Check if document already exists
        $existingDocument = Document::where('intake_id', $this->intake->id)
            ->where(function($query) {
                $query->where('file_path', $this->file->storage_path)
                      ->orWhere('filepath', $this->file->storage_path);
            })
            ->first();

        if ($existingDocument) {
            Log::info('ExtractPdfDataJob: Using existing document', [
                'intake_id' => $this->intake->id,
                'document_id' => $existingDocument->id
            ]);
            return $existingDocument;
        }

        // Create new document
        $document = Document::create([
            'intake_id' => $this->intake->id,
            'filename' => $this->file->filename,
            'original_filename' => $this->file->filename,
            'file_path' => $this->file->storage_path,
            'filepath' => $this->file->storage_path,
            'mime_type' => $this->file->mime_type,
            'file_size' => $this->file->file_size,
            'storage_disk' => $this->file->storage_disk,
            'status' => 'pending',
            'extraction_status' => 'processing',
            'extraction_confidence' => 0.0,
        ]);

        Log::info('ExtractPdfDataJob: Created new document', [
            'intake_id' => $this->intake->id,
            'file_id' => $this->file->id,
            'document_id' => $document->id
        ]);

        return $document;
    }

    /**
     * Update the processed documents count on the intake
     */
    private function updateProcessedDocumentsCount(): void
    {
        $this->intake->increment('processed_documents');
        
        Log::debug('ExtractPdfDataJob: Updated processed documents count', [
            'intake_id' => $this->intake->id,
            'processed_documents' => $this->intake->fresh()->processed_documents,
            'total_documents' => $this->intake->total_documents
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ExtractPdfDataJob failed permanently', [
            'intake_id' => $this->intake->id,
            'file_id' => $this->file->id,
            'filename' => $this->file->filename,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Update intake status to indicate permanent failure
        $this->intake->update([
            'status' => 'failed',
            'notes' => array_merge($this->intake->notes ?? [], [
                'permanent_extraction_error' => $exception->getMessage(),
                'failed_file' => $this->file->filename,
                'attempts' => $this->attempts()
            ])
        ]);
    }
}


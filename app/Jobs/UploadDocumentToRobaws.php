<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\MultiDocumentUploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UploadDocumentToRobaws implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $maxExceptions = 2;
    public int $timeout = 120;

    public function __construct(
        protected Document $document,
        protected ?string $quotationId = null
    ) {}

    public function handle(MultiDocumentUploadService $uploadService): void
    {
        // Refresh document to get latest data
        $this->document->refresh();

        // Use provided quotation ID or get from document/extraction
        $quotationId = $this->quotationId;
        
        if (!$quotationId) {
            // Try to get from document first
            $quotationId = $this->document->robaws_quotation_id;
            
            // If still no quotation ID, try from extraction
            if (!$quotationId) {
                $extraction = $this->document->extractions()
                    ->whereNotNull('robaws_quotation_id')
                    ->latest()
                    ->first();
                    
                if ($extraction && $extraction->robaws_quotation_id) {
                    $quotationId = $extraction->robaws_quotation_id;
                }
            }
        }

        // Skip if no Robaws quotation ID
        if (!$quotationId) {
            Log::warning('Cannot upload document without Robaws quotation ID', [
                'document_id' => $this->document->id,
                'filename' => $this->document->filename
            ]);
            return;
        }

        // Skip if already uploaded
        if ($this->document->upload_status === 'uploaded') {
            Log::info('Document already uploaded to Robaws', [
                'document_id' => $this->document->id,
                'robaws_quotation_id' => $quotationId
            ]);
            return;
        }

        // Update status to indicate upload is starting
        $this->document->update(['upload_status' => 'uploading']);

        try {
            Log::info('Starting document upload to existing quotation', [
                'document_id' => $this->document->id,
                'filename' => $this->document->filename,
                'quotation_id' => $quotationId,
                'attempt' => $this->attempts()
            ]);

            $result = $uploadService->uploadDocumentToQuotation($this->document, $quotationId);
            
            if ($result) {
                // Update document with upload info - use SAME quotation ID
                $this->document->update([
                    'robaws_quotation_id' => $quotationId, // Same as extraction
                    'robaws_document_id' => $result['document_id'] ?? null,
                    'upload_status' => 'uploaded',
                    'upload_error' => null
                ]);

                Log::info('Document uploaded to quotation successfully', [
                    'document_id' => $this->document->id,
                    'filename' => $this->document->filename,
                    'quotation_id' => $quotationId,
                    'robaws_document_id' => $result['document_id'] ?? null,
                    'attempt' => $this->attempts()
                ]);
            } else {
                throw new \Exception('Upload service returned false - upload failed');
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to upload document to Robaws', [
                'document_id' => $this->document->id,
                'filename' => $this->document->filename,
                'quotation_id' => $quotationId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries
            ]);

            // Update document with error status
            $this->document->update([
                'upload_status' => 'failed',
                'upload_error' => $e->getMessage()
            ]);
            
            // Retry the job with exponential backoff if attempts remain
            if ($this->attempts() < $this->tries) {
                $delay = 60 * pow(2, $this->attempts()); // 60s, 120s, 240s
                Log::info('Retrying document upload', [
                    'document_id' => $this->document->id,
                    'next_attempt' => $this->attempts() + 1,
                    'delay_seconds' => $delay
                ]);
                $this->release($delay);
            } else {
                // All attempts failed
                Log::error('All upload attempts failed for document', [
                    'document_id' => $this->document->id,
                    'filename' => $this->document->filename,
                    'total_attempts' => $this->attempts()
                ]);
            }
            
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Upload job failed permanently', [
            'document_id' => $this->document->id,
            'filename' => $this->document->filename,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Mark as permanently failed
        $this->document->update([
            'upload_status' => 'failed_permanent',
            'upload_error' => 'All upload attempts failed: ' . $exception->getMessage()
        ]);
    }
}

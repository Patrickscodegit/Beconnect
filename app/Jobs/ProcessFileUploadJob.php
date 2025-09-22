<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\MultiDocumentUploadService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessFileUploadJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes timeout for file uploads
    public $tries = 3; // Retry up to 3 times

    public function __construct(
        private Document $document
    ) {}

    /**
     * Execute the job.
     */
    public function handle(MultiDocumentUploadService $uploadService): void
    {
        Log::info('Starting file upload to Robaws', [
            'document_id' => $this->document->id,
            'filename' => $this->document->filename,
            'quotation_id' => $this->document->robaws_quotation_id,
            'current_upload_status' => $this->document->upload_status
        ]);

        try {
            // Check if file already uploaded
            if ($this->document->upload_status === 'uploaded') {
                Log::info('File already uploaded, skipping upload', [
                    'document_id' => $this->document->id,
                    'filename' => $this->document->filename
                ]);
                return;
            }

            // Check if we have a quotation ID
            if (!$this->document->robaws_quotation_id) {
                throw new \RuntimeException('No Robaws quotation ID found for document');
            }

            // Update status to uploading
            $this->document->update(['upload_status' => 'uploading']);

            // Upload the file
            $result = $uploadService->uploadDocumentToQuotation($this->document);
            
            if ($result) {
                Log::info('File uploaded successfully to Robaws', [
                    'document_id' => $this->document->id,
                    'filename' => $this->document->filename,
                    'quotation_id' => $this->document->robaws_quotation_id
                ]);

                // Update status to uploaded
                $this->document->update(['upload_status' => 'uploaded']);
            } else {
                throw new \RuntimeException('File upload returned false');
            }

        } catch (\Exception $e) {
            Log::error('File upload failed', [
                'document_id' => $this->document->id,
                'filename' => $this->document->filename,
                'quotation_id' => $this->document->robaws_quotation_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update status to failed
            $this->document->update([
                'upload_status' => 'failed',
                'upload_error' => $e->getMessage(),
                'upload_error_at' => now()
            ]);

            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessFileUploadJob failed permanently', [
            'document_id' => $this->document->id,
            'filename' => $this->document->filename,
            'quotation_id' => $this->document->robaws_quotation_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Update document status to indicate permanent failure
        $this->document->update([
            'upload_status' => 'failed',
            'upload_error' => 'Job failed after ' . $this->attempts() . ' attempts: ' . $exception->getMessage(),
            'upload_error_at' => now()
        ]);
    }
}

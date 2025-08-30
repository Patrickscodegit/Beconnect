<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\RobawsFileUploader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadPdfToRobaws implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private Document $document,
        private string $robawsOfferId
    ) {}

    public function handle(RobawsFileUploader $uploader): void
    {
        try {
            Log::info('Starting PDF upload to Robaws', [
                'document_id' => $this->document->id,
                'robaws_offer_id' => $this->robawsOfferId,
                'filename' => $this->document->filename
            ]);

            // Get the PDF file content
            $filePath = $this->document->file_path;
            $disk = $this->document->storage_disk ?? 'local';
            
            if (!Storage::disk($disk)->exists($filePath)) {
                throw new \Exception("PDF file not found: {$filePath}");
            }

            $contents = Storage::disk($disk)->get($filePath);
            $filename = $this->document->filename;

            // Upload to Robaws offer
            $result = $uploader->uploadPdf('offers', $this->robawsOfferId, $filename, $contents);

            // Update document with Robaws file reference
            $this->document->update([
                'robaws_document_id' => $result['id'] ?? null,
                'robaws_document_data' => $result,
                'robaws_file_uploaded_at' => now(),
            ]);

            Log::info('PDF successfully uploaded to Robaws', [
                'document_id' => $this->document->id,
                'robaws_offer_id' => $this->robawsOfferId,
                'robaws_document_id' => $result['id'] ?? null,
                'filename' => $filename,
                'file_size' => strlen($contents)
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to upload PDF to Robaws', [
                'document_id' => $this->document->id,
                'robaws_offer_id' => $this->robawsOfferId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update document with error status
            $this->document->update([
                'robaws_file_upload_error' => $e->getMessage(),
                'robaws_file_upload_failed_at' => now(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PDF upload to Robaws job failed permanently', [
            'document_id' => $this->document->id,
            'robaws_offer_id' => $this->robawsOfferId,
            'error' => $exception->getMessage()
        ]);

        $this->document->update([
            'robaws_file_upload_error' => $exception->getMessage(),
            'robaws_file_upload_failed_at' => now(),
        ]);
    }
}

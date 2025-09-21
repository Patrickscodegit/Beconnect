<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Quotation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class MultiDocumentUploadService
{
    public function __construct(
        private RobawsClient $robawsClient,
        private DocumentConversion $documentConversion
    ) {}

    /**
     * Upload a single document to an existing Robaws quotation with proper conversion and idempotency
     */
    public function uploadDocumentToQuotation(Document $document): array
    {
        if (!$document->robaws_quotation_id) {
            throw new \Exception('Document does not have a Robaws quotation ID');
        }

        // Check idempotency - avoid duplicate uploads
        $uploadKey = $this->generateUploadKey($document);
        if ($this->isAlreadyUploaded($uploadKey)) {
            Log::info('Document already uploaded, skipping', [
                'document_id' => $document->id,
                'upload_key' => $uploadKey
            ]);
            return ['status' => 'skipped', 'reason' => 'already_uploaded'];
        }

        try {
            // Convert document to proper format for Robaws
            $uploadPath = $this->documentConversion->ensurePdfForUpload($document);
            
            if (!file_exists($uploadPath)) {
                throw new \Exception('Converted document file not found: ' . $uploadPath);
            }

            // Generate proper filename for Robaws
            $uploadFilename = $this->generateUploadFilename($document, $uploadPath);
            
            // Get proper content type
            $contentType = $this->getContentType($uploadPath);

            Log::info('Uploading document to Robaws', [
                'document_id' => $document->id,
                'robaws_quotation_id' => $document->robaws_quotation_id,
                'original_filename' => $document->filename,
                'upload_filename' => $uploadFilename,
                'upload_path' => $uploadPath,
                'content_type' => $contentType,
                'file_size' => filesize($uploadPath)
            ]);

            // Upload with retry logic
            $result = $this->uploadWithRetry(
                $document->robaws_quotation_id,
                $uploadPath,
                $uploadFilename,
                $contentType
            );

            // Mark as uploaded to prevent duplicates
            $this->markAsUploaded($uploadKey);

            // Clean up temporary files if they were created
            $this->cleanupTempFiles($uploadPath, $document);

            return $result;

        } catch (\Throwable $e) {
            Log::error('Document upload failed', [
                'document_id' => $document->id,
                'robaws_quotation_id' => $document->robaws_quotation_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Upload all documents related to a quotation
     */
    public function uploadQuotationDocuments(Quotation $quotation): array
    {
        Log::info('Starting multi-document upload for quotation', [
            'quotation_id' => $quotation->id,
            'robaws_id' => $quotation->robaws_id
        ]);

        // Collect all related documents
        $documents = $this->collectQuotationDocuments($quotation);
        
        if ($documents->isEmpty()) {
            Log::warning('No documents found for quotation', [
                'quotation_id' => $quotation->id
            ]);
            return [];
        }

        // Upload each document individually with conversion
        $results = [];
        foreach ($documents as $document) {
            try {
                if ($document->robaws_quotation_id) {
                    $result = $this->uploadDocumentToQuotation($document);
                    $results[] = $result;
                }
            } catch (\Throwable $e) {
                Log::error('Failed to upload document in batch', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage()
                ]);
                $results[] = [
                    'status' => 'error',
                    'document_id' => $document->id,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Generate idempotency key for upload
     */
    private function generateUploadKey(Document $document): string
    {
        $originalPath = $this->getDocumentFilePath($document);
        $fileHash = file_exists($originalPath) ? hash_file('sha256', $originalPath) : 'unknown';
        
        return sprintf(
            'upload_%d_%s_%s',
            $document->id,
            $document->robaws_quotation_id,
            substr($fileHash, 0, 8)
        );
    }

    /**
     * Check if document is already uploaded
     */
    private function isAlreadyUploaded(string $uploadKey): bool
    {
        return Cache::has($uploadKey);
    }

    /**
     * Mark document as uploaded
     */
    private function markAsUploaded(string $uploadKey): void
    {
        // Cache for 24 hours to prevent duplicates
        Cache::put($uploadKey, true, now()->addHours(24));
    }

    /**
     * Generate proper filename for Robaws upload
     */
    private function generateUploadFilename(Document $document, string $uploadPath): string
    {
        $originalName = pathinfo($document->filename, PATHINFO_FILENAME);
        $uploadExtension = pathinfo($uploadPath, PATHINFO_EXTENSION);
        
        // If file was converted, indicate it in filename
        if ($uploadPath !== $this->getDocumentFilePath($document)) {
            return $originalName . '-converted.' . $uploadExtension;
        }
        
        return $originalName . '.' . $uploadExtension;
    }

    /**
     * Get proper content type for upload
     */
    private function getContentType(string $filePath): string
    {
        $mimeType = mime_content_type($filePath);
        
        // Ensure we have proper MIME types for Robaws
        return match($mimeType) {
            'application/pdf' => 'application/pdf',
            'image/jpeg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/gif' => 'image/gif',
            default => $mimeType ?? 'application/octet-stream'
        };
    }

    /**
     * Upload with exponential backoff retry logic
     */
    private function uploadWithRetry(string $quotationId, string $filePath, string $filename, string $contentType, int $maxRetries = 3): array
    {
        $attempt = 1;
        
        while ($attempt <= $maxRetries) {
            try {
                // Use uploadMultipleDocuments which handles both small and large files automatically
                $results = $this->robawsClient->uploadMultipleDocuments(
                    'offer',
                    $quotationId,
                    [$filePath]
                );
                
                // Extract the result for our single file
                $result = $results[0] ?? null;
                
                if (!$result || $result['status'] !== 'success') {
                    throw new \Exception($result['error'] ?? 'Upload failed');
                }
                
                Log::info('Document upload successful', [
                    'quotation_id' => $quotationId,
                    'filename' => $filename,
                    'attempt' => $attempt,
                    'method' => $result['method'] ?? 'unknown'
                ]);
                
                return ['id' => $result['document_id']];
                
            } catch (\Throwable $e) {
                $statusCode = method_exists($e, 'getCode') ? $e->getCode() : 500;
                
                // Don't retry 4xx errors (client errors)
                if ($statusCode >= 400 && $statusCode < 500) {
                    Log::error('Upload failed with client error - not retrying', [
                        'quotation_id' => $quotationId,
                        'filename' => $filename,
                        'status_code' => $statusCode,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
                
                Log::warning('Upload attempt failed', [
                    'quotation_id' => $quotationId,
                    'filename' => $filename,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempt >= $maxRetries) {
                    throw $e;
                }
                
                // Exponential backoff: 1s, 2s, 4s
                $delay = pow(2, $attempt - 1);
                sleep($delay);
                $attempt++;
            }
        }
    }

    /**
     * Clean up temporary files created during conversion
     */
    private function cleanupTempFiles(string $uploadPath, Document $document): void
    {
        $originalPath = $this->getDocumentFilePath($document);
        
        // Only clean up if this is a converted file (different from original)
        if ($uploadPath !== $originalPath && file_exists($uploadPath)) {
            // Check if it's in temp directory or has "-converted" in name
            if (str_contains($uploadPath, sys_get_temp_dir()) || str_contains($uploadPath, '-converted')) {
                unlink($uploadPath);
                Log::debug('Cleaned up temporary file', ['path' => $uploadPath]);
            }
        }
    }

    /**
     * Get the file path for a document
     */
    private function getDocumentFilePath(Document $document): string
    {
        return Storage::disk($document->storage_disk)->path($document->file_path);
    }

    /**
     * Collect all documents related to a quotation
     */
    private function collectQuotationDocuments(Quotation $quotation): \Illuminate\Database\Eloquent\Collection
    {
        // Get documents by robaws_quotation_id
        return Document::where('robaws_quotation_id', $quotation->robaws_id)->get();
    }
}

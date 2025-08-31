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

        // Prepare file list for upload
        $files = [];
        foreach ($documents as $document) {
            $filePath = $this->getDocumentFilePath($document);
            if ($filePath && file_exists($filePath)) {
                $files[] = $filePath;
            } else {
                Log::warning('Document file not found', [
                    'document_id' => $document->id,
                    'file_path' => $filePath
                ]);
            }
        }

        if (empty($files)) {
            Log::error('No valid files found for upload', [
                'quotation_id' => $quotation->id,
                'documents_checked' => $documents->count()
            ]);
            return [];
        }

        try {
            // Upload files to Robaws
            $uploadResults = $this->robawsClient->uploadMultipleDocuments(
                'offer',
                $quotation->robaws_id,
                $files
            );

            // Update database with upload status
            $this->updateDocumentUploadStatus($documents, $uploadResults);

            Log::info('Multi-document upload completed', [
                'quotation_id' => $quotation->id,
                'total_files' => count($files),
                'successful_uploads' => count(array_filter($uploadResults, fn($r) => $r['status'] === 'success')),
                'failed_uploads' => count(array_filter($uploadResults, fn($r) => $r['status'] === 'error'))
            ]);

            return $uploadResults;

        } catch (\Exception $e) {
            Log::error('Multi-document upload failed', [
                'quotation_id' => $quotation->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

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
                $result = $this->robawsClient->uploadDocumentToOffer(
                    $quotationId,
                    $filePath,
                    $filename,
                    $contentType
                );
                
                Log::info('Document upload successful', [
                    'quotation_id' => $quotationId,
                    'filename' => $filename,
                    'attempt' => $attempt
                ]);
                
                return $result;
                
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
     * Retry failed uploads for a quotation
     */
    public function retryFailedUploads(Quotation $quotation): array
    {
        $failedDocuments = Document::where('robaws_quotation_id', $quotation->robaws_id)
            ->where('upload_status', 'failed')
            ->get();

        if ($failedDocuments->isEmpty()) {
            return [];
        }

        Log::info('Retrying failed uploads', [
            'quotation_id' => $quotation->id,
            'failed_documents' => $failedDocuments->count()
        ]);

        $results = [];
        foreach ($failedDocuments as $document) {
            $results[] = $this->uploadDocumentToQuotation($document);
        }

        return $results;
    }

    /**
     * Get upload status for a quotation
     */
    public function getUploadStatus(Quotation $quotation): array
    {
        $documents = $this->collectQuotationDocuments($quotation);
        
        $status = [
            'total_documents' => $documents->count(),
            'uploaded' => $documents->where('upload_status', 'uploaded')->count(),
            'failed' => $documents->where('upload_status', 'failed')->count(),
            'pending' => $documents->whereNull('upload_status')->count(),
            'documents' => []
        ];

        foreach ($documents as $document) {
            $status['documents'][] = [
                'id' => $document->id,
                'filename' => $document->original_filename ?? $document->filename,
                'mime_type' => $document->mime_type,
                'file_size' => $document->file_size,
                'upload_status' => $document->upload_status,
                'robaws_document_id' => $document->robaws_document_id,
                'uploaded_at' => $document->robaws_uploaded_at,
                'error' => $document->upload_error
            ];
        }

        return $status;
    }

    /**
     * Collect all documents related to a quotation
     */
    private function collectQuotationDocuments(Quotation $quotation): \Illuminate\Support\Collection
    {
        // Primary document
        $documents = collect();
        if ($quotation->document_id) {
            $primaryDoc = Document::find($quotation->document_id);
            if ($primaryDoc) {
                $documents->push($primaryDoc);
            }
        }

        // Related documents by Robaws quotation ID
        $relatedByRobaws = Document::where('robaws_quotation_id', $quotation->robaws_id)
            ->where('id', '!=', $quotation->document_id)
            ->get();
        $documents = $documents->merge($relatedByRobaws);

        // Related documents by intake ID (if available)
        if ($quotation->document && $quotation->document->intake_id) {
            $relatedByIntake = Document::where('intake_id', $quotation->document->intake_id)
                ->whereNotIn('id', $documents->pluck('id'))
                ->get();
            $documents = $documents->merge($relatedByIntake);
        }

        return $documents->unique('id');
    }

    /**
     * Get the full file path for a document
     */
    private function getDocumentFilePath(Document $document): ?string
    {
        // Use the proper storage disk to get the full path
        $disk = $document->storage_disk ?: config('filesystems.default', 'local');
        
        try {
            // Get full path using storage disk
            $fullPath = Storage::disk($disk)->path($document->file_path);
            
            if (file_exists($fullPath)) {
                return $fullPath;
            }
            
            Log::warning('File not found using storage disk path', [
                'document_id' => $document->id,
                'storage_disk' => $disk,
                'file_path' => $document->file_path,
                'full_path' => $fullPath
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting storage disk path', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
        }
        
        // Fallback: Try different possible locations (keep legacy support)
        $possiblePaths = [
            storage_path('app/' . $document->file_path),
            storage_path('app/private/documents/' . $document->file_path),
            storage_path('app/private/documents/' . $document->filename),
            $document->file_path
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                Log::info('File found using fallback path', [
                    'document_id' => $document->id,
                    'path' => $path
                ]);
                return $path;
            }
        }

        // Try to find by pattern if filename has ID prefix
        if (str_contains($document->filename, '_')) {
            $pattern = storage_path('app/private/documents/*_' . $document->filename);
            $matches = glob($pattern);
            if (!empty($matches)) {
                return $matches[0];
            }
        }

        return null;
    }

    /**
     * Update document upload status based on results
     */
    private function updateDocumentUploadStatus(\Illuminate\Support\Collection $documents, array $uploadResults): void
    {
        $resultsByFile = collect($uploadResults)->keyBy('file');

        foreach ($documents as $document) {
            $fileName = $document->original_filename ?? $document->filename;
            $result = $resultsByFile->get($fileName);

            if (!$result) {
                continue;
            }

            if ($result['status'] === 'success') {
                $document->update([
                    'robaws_document_id' => $result['document_id'],
                    'robaws_uploaded_at' => now(),
                    'upload_status' => 'uploaded',
                    'upload_method' => $result['method']
                ]);
            } else {
                $document->update([
                    'upload_status' => 'failed',
                    'upload_error' => $result['error'],
                    'robaws_upload_attempted_at' => now(),
                    'upload_method' => $result['method']
                ]);
            }
        }
    }
}

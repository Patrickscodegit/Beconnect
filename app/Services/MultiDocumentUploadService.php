<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Quotation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MultiDocumentUploadService
{
    public function __construct(
        private RobawsClient $robawsClient
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
     * Upload a single document to an existing Robaws quotation
     */
    public function uploadDocumentToQuotation(Document $document): array
    {
        if (!$document->robaws_quotation_id) {
            throw new \Exception('Document does not have a Robaws quotation ID');
        }

        $filePath = $this->getDocumentFilePath($document);
        if (!$filePath || !file_exists($filePath)) {
            throw new \Exception('Document file not found: ' . $filePath);
        }

        Log::info('Uploading single document to Robaws', [
            'document_id' => $document->id,
            'robaws_quotation_id' => $document->robaws_quotation_id,
            'file_size' => filesize($filePath),
            'mime_type' => $document->mime_type
        ]);

        try {
            $uploadResult = $this->robawsClient->uploadDirectToEntity(
                'offer',
                $document->robaws_quotation_id,
                $filePath
            );

            // Update document with Robaws document ID
            $document->update([
                'robaws_document_id' => $uploadResult['id'] ?? null,
                'robaws_uploaded_at' => now(),
                'upload_status' => 'uploaded'
            ]);

            Log::info('Document uploaded successfully', [
                'document_id' => $document->id,
                'robaws_document_id' => $uploadResult['id'] ?? null
            ]);

            return [
                'status' => 'success',
                'document_id' => $document->id,
                'robaws_document_id' => $uploadResult['id'] ?? null,
                'file_name' => $document->original_filename ?? $document->filename
            ];

        } catch (\Exception $e) {
            // Update document with error status
            $document->update([
                'upload_status' => 'failed',
                'upload_error' => $e->getMessage(),
                'robaws_upload_attempted_at' => now()
            ]);

            Log::error('Single document upload failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'error',
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'file_name' => $document->original_filename ?? $document->filename
            ];
        }
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

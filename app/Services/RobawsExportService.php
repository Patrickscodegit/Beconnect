<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Intake;
use App\Services\RobawsIntegrationService;
use App\Services\MultiDocumentUploadService;
use App\Services\RobawsClient;
use App\Exceptions\RobawsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RobawsExportService
{
    protected $robawsService;
    protected $uploadService;
    protected $robawsClient;

    public function __construct(
        RobawsIntegrationService $robawsService,
        MultiDocumentUploadService $uploadService,
        RobawsClient $robawsClient
    ) {
        $this->robawsService = $robawsService;
        $this->uploadService = $uploadService;
        $this->robawsClient = $robawsClient;
    }

    /**
     * Export a document to Robaws and upload the file immediately
     */
    public function exportDocument(Document $document): array
    {
        try {
            Log::info('Starting document export to Robaws', [
                'document_id' => $document->id,
                'file_name' => $document->file_name
            ]);

            // Step 1: Get extraction data
            $extraction = $document->extractions()
                ->where('status', 'completed')
                ->whereNotNull('extracted_data')
                ->latest()
                ->first();

            if (!$extraction) {
                throw new RobawsException('No completed extraction found for document');
            }

            // Skip if already exported
            if ($extraction->robaws_quotation_id && $document->robaws_document_id) {
                Log::info('Document already fully exported', [
                    'document_id' => $document->id,
                    'quotation_id' => $extraction->robaws_quotation_id,
                    'robaws_document_id' => $document->robaws_document_id
                ]);
                
                return [
                    'success' => true,
                    'quotation_id' => $extraction->robaws_quotation_id,
                    'document_id' => $document->robaws_document_id,
                    'already_exported' => true
                ];
            }

            // Step 2: Create quotation if needed
            $quotationId = $extraction->robaws_quotation_id;
            
            if (!$quotationId) {
                Log::info('Creating new quotation in Robaws', ['document_id' => $document->id]);
                
                $result = $this->robawsService->createOfferFromDocument($document);
                
                if (!$result || !isset($result['id'])) {
                    throw new RobawsException('Failed to create quotation in Robaws');
                }
                
                $quotationId = $result['id'];
                
                // Update both extraction and document with quotation ID
                $extraction->update(['robaws_quotation_id' => $quotationId]);
                $document->update(['robaws_quotation_id' => $quotationId]);
                
                Log::info('Quotation created successfully', [
                    'document_id' => $document->id,
                    'quotation_id' => $quotationId
                ]);
            }

            // Step 3: Upload document file immediately (don't rely on observers)
            if (!$document->robaws_document_id) {
                Log::info('Uploading document file to Robaws', [
                    'document_id' => $document->id,
                    'quotation_id' => $quotationId
                ]);

                $uploadResult = $this->uploadDocumentDirectly($document, $quotationId);
                
                if (!$uploadResult['success']) {
                    throw new RobawsException('Failed to upload document: ' . ($uploadResult['error'] ?? 'Unknown error'));
                }
                
                Log::info('Document uploaded successfully', [
                    'document_id' => $document->id,
                    'robaws_document_id' => $uploadResult['robaws_document_id']
                ]);
            }

            return [
                'success' => true,
                'quotation_id' => $quotationId,
                'document_id' => $document->robaws_document_id,
                'message' => 'Document exported and uploaded successfully'
            ];

        } catch (\Exception $e) {
            Log::error('Failed to export document', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Normalize disk key to avoid double prefixes in file paths
     * This is needed for DigitalOcean Spaces which sometimes double the prefix
     */
    protected function normalizeDiskKey(string $path, ?string $diskRoot = null): string
    {
        if (!$diskRoot) {
            return $path;
        }
        
        // Remove leading slash from disk root for comparison
        $cleanDiskRoot = ltrim($diskRoot, '/');
        
        // If path already starts with the disk root, remove the duplicate
        if (str_starts_with($path, $cleanDiskRoot . '/')) {
            return substr($path, strlen($cleanDiskRoot) + 1);
        }
        
        return $path;
    }

    /**
     * Upload document directly without relying on observers - FIXED for DO Spaces
     */
    protected function uploadDocumentDirectly(Document $document, string $quotationId): array
    {
        try {
            // Use storage disk instead of local file paths
            $disk = Storage::disk($document->storage_disk ?? 'documents');
            
            // Normalize the file path to handle potential double prefix
            $filepath = $this->normalizeDiskKey($document->file_path, config('filesystems.disks.documents.root'));
            
            // Check if file exists in storage
            if (!$disk->exists($filepath)) {
                // Try without normalization if normalized path doesn't exist
                if (!$disk->exists($document->file_path)) {
                    throw new RobawsException("Document file not found in storage. Tried: '{$filepath}' and '{$document->file_path}'");
                }
                $filepath = $document->file_path;
            }

            // Get file info from storage (works with both local and cloud)
            $mimeType = $disk->mimeType($filepath) ?: 'application/pdf';
            $fileSize = $disk->size($filepath);
            $fileName = basename($document->file_path); // Use original filename
            
            // For files over 50MB, consider using temporary URL instead
            if ($fileSize > 50 * 1024 * 1024) {
                Log::warning('Large file detected, skipping direct upload', [
                    'document_id' => $document->id,
                    'file_size' => $fileSize
                ]);
                throw new RobawsException('File too large for direct upload');
            }

            // Stream the file from storage
            $fileStream = $disk->readStream($filepath);
            
            if (!is_resource($fileStream)) {
                throw new RobawsException("Failed to read file stream from storage");
            }

            Log::info('Found document file, attempting upload', [
                'document_id' => $document->id,
                'filepath_used' => $filepath,
                'file_size' => $fileSize,
                'quotation_id' => $quotationId
            ]);

            try {
                // Upload using stream instead of file path
                $uploadResult = $this->robawsClient->uploadDocument($quotationId, [
                    'stream' => $fileStream,
                    'filename' => $fileName,
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize
                ]);
                
                if (!$uploadResult || !isset($uploadResult['id'])) {
                    throw new RobawsException('Upload failed - no document ID returned');
                }

                // Update document with Robaws document ID
                $document->update([
                    'robaws_document_id' => $uploadResult['id'],
                    'upload_status' => 'uploaded',
                    'uploaded_at' => now()
                ]);

                Log::info('Document upload completed', [
                    'document_id' => $document->id,
                    'robaws_document_id' => $uploadResult['id'],
                    'filepath_used' => $filepath
                ]);

                return [
                    'success' => true,
                    'robaws_document_id' => $uploadResult['id']
                ];
            } finally {
                // Always close the stream
                if (is_resource($fileStream)) {
                    fclose($fileStream);
                }
            }

        } catch (\Exception $e) {
            Log::error('Document upload failed', [
                'document_id' => $document->id,
                'filepath' => $document->file_path,
                'error' => $e->getMessage()
            ]);

            $document->update(['upload_status' => 'failed']);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get the actual file path for a document
     */
    protected function getDocumentFilePath(Document $document): ?string
    {
        // First, determine the storage disk
        $disk = $document->storage_disk ?? $document->disk ?? 'local';
        
        // Try different possible paths
        $possiblePaths = [
            $document->file_path,
            $document->path,
            'documents/' . basename($document->file_path ?? $document->path ?? ''),
            'private/documents/' . basename($document->file_path ?? $document->path ?? ''),
            'private/' . basename($document->file_path ?? $document->path ?? ''),
        ];

        // Remove empty paths
        $possiblePaths = array_filter($possiblePaths);

        Log::info('Searching for document file', [
            'document_id' => $document->id,
            'storage_disk' => $disk,
            'possible_paths' => $possiblePaths
        ]);

        foreach ($possiblePaths as $path) {
            if ($path && Storage::disk($disk)->exists($path)) {
                $fullPath = Storage::disk($disk)->path($path);
                Log::info('Found document file', [
                    'document_id' => $document->id,
                    'path' => $path,
                    'full_path' => $fullPath
                ]);
                return $fullPath;
            }
        }

        Log::warning('Document file not found in any expected location', [
            'document_id' => $document->id,
            'storage_disk' => $disk,
            'tried_paths' => $possiblePaths
        ]);

        return null;
    }

    /**
     * Export all documents in an intake
     */
    public function exportIntake(Intake $intake): array
    {
        Log::info('Starting intake export to Robaws', [
            'intake_id' => $intake->id
        ]);

        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        $documents = $intake->documents()
            ->whereHas('extractions', function ($query) {
                $query->where('status', 'completed')
                      ->whereNotNull('extracted_data');
            })
            ->get();

        Log::info('Found documents to export', [
            'intake_id' => $intake->id,
            'document_count' => $documents->count()
        ]);

        foreach ($documents as $document) {
            $result = $this->exportDocument($document);
            
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = "Document {$document->id}: " . ($result['error'] ?? 'Unknown error');
            }
        }

        Log::info('Intake export completed', [
            'intake_id' => $intake->id,
            'success_count' => $results['success'],
            'failed_count' => $results['failed']
        ]);

        return $results;
    }

    /**
     * Export a single document by ID
     */
    public function exportDocumentById(int $documentId): array
    {
        $document = Document::find($documentId);
        
        if (!$document) {
            return [
                'success' => false,
                'error' => 'Document not found'
            ];
        }

        return $this->exportDocument($document);
    }
}

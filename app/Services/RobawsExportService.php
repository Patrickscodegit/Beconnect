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
     * Upload document directly without relying on observers
     */
    protected function uploadDocumentDirectly(Document $document, string $quotationId): array
    {
        try {
            // Get file path - check multiple possible locations
            $filePath = $this->getDocumentFilePath($document);
            
            if (!$filePath) {
                throw new RobawsException('Document file not found');
            }

            Log::info('Found document file, attempting upload', [
                'document_id' => $document->id,
                'file_path' => $filePath,
                'quotation_id' => $quotationId
            ]);

            // Upload directly to the offer
            $uploadResult = $this->robawsClient->uploadDirectToEntity('offer', $quotationId, $filePath);
            
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
                'robaws_document_id' => $uploadResult['id']
            ]);

            return [
                'success' => true,
                'robaws_document_id' => $uploadResult['id']
            ];

        } catch (\Exception $e) {
            Log::error('Document upload failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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

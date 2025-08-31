<?php

namespace App\Services;

use App\Services\Robaws\RobawsExportService as NewRobawsExportService;

/**
 * @deprecated Use App\Services\Robaws\RobawsExportService instead.
 * This class is maintained for backward compatibility but will be removed.
 * All new code should use the new service through DI container.
 */
class RobawsExportService extends NewRobawsExportService
{
    // This class now extends the new service, ensuring all existing
    // code continues to work while using the improved implementation
}
     * Hash a file on any disk without loading huge files fully into memory.
     */
    private function sha256ForDiskFile($disk, string $path, int $size): string
    {
        $threshold = 20 * 1024 * 1024; // 20MB
        if ($size <= $threshold) {
            return hash('sha256', $disk->get($path));
        }
        
        $h = hash_init('sha256');
        $stream = $disk->readStream($path);
        if (!$stream) {
            throw new \RuntimeException("Hash stream failed: {$path}");
        }
        
        try {
            while (!feof($stream)) {
                $buf = fread($stream, 1024 * 1024);
                if ($buf === false) break;
                hash_update($h, $buf);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        
        return hash_final($h);
    }

    /**
     * Idempotent upload: skip if same sha256 already present for this offer.
     */
    protected function uploadDocumentToRobaws(Document $document, string $robawsOfferId): array
    {
        $disk = Storage::disk('documents');
        $path = $document->filepath;

        if (!$disk->exists($path)) {
            return [
                'status' => 'error',
                'offer_id' => $robawsOfferId,
                'filename' => $path,
                'reason' => 'file missing'
            ];
        }

        $size = $disk->size($path);
        $filename = basename($path);
        $mime = $disk->mimeType($path) ?: 'application/octet-stream';

        // 1) Content hash
        $sha256 = $this->sha256ForDiskFile($disk, $path, $size);

        // 2) Lock to prevent concurrent double-uploads
        $lockKey = "robaws:upload:{$robawsOfferId}:{$sha256}";
        $lock = Cache::lock($lockKey, 20);
        if (!$lock->get()) {
            Log::info('Robaws dedupe: lock wait/skip', compact('robawsOfferId', 'sha256', 'filename'));
            return [
                'status' => 'exists',
                'offer_id' => $robawsOfferId,
                'filename' => $filename,
                'reason' => 'upload in progress'
            ];
        }

        try {
            // 3) Local ledger check
            $already = RobawsDocument::where('robaws_offer_id', $robawsOfferId)
                ->where('sha256', $sha256)
                ->first();
            if ($already) {
                Log::info('Robaws dedupe: ledger skip', [
                    'robaws_offer_id' => $robawsOfferId,
                    'sha256' => $sha256,
                    'filename' => $filename,
                ]);
                return [
                    'status' => 'exists',
                    'offer_id' => $robawsOfferId,
                    'filename' => $filename,
                    'robaws_doc_id' => $already->robaws_document_id,
                    'reason' => 'ledger match'
                ];
            }

            // 4) Remote preflight (best-effort)
            try {
                $remoteDocs = $this->robawsClient->listOfferDocuments($robawsOfferId);
                $matched = collect($remoteDocs)->first(function ($d) use ($filename, $size) {
                    // If Robaws API exposes checksum, compare that here too.
                    $remoteName = $d['name'] ?? $d['filename'] ?? '';
                    $remoteSize = (int)($d['size'] ?? 0);
                    return (strcasecmp($remoteName, $filename) === 0) && ($remoteSize === (int)$size);
                });

                if ($matched) {
                    RobawsDocument::create([
                        'document_id' => $document->id,
                        'robaws_offer_id' => $robawsOfferId,
                        'robaws_document_id' => $matched['id'] ?? null,
                        'sha256' => $sha256,
                        'filename' => $filename,
                        'filesize' => $size,
                    ]);
                    Log::info('Robaws dedupe: remote match, ledger seeded', [
                        'robaws_offer_id' => $robawsOfferId,
                        'sha256' => $sha256,
                        'filename' => $filename,
                    ]);
                    return [
                        'status' => 'exists',
                        'offer_id' => $robawsOfferId,
                        'filename' => $filename,
                        'robaws_doc_id' => $matched['id'] ?? null,
                        'reason' => 'remote match'
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('Robaws preflight list failed (continuing to upload)', [
                    'offer' => $robawsOfferId,
                    'error' => $e->getMessage(),
                ]);
            }

            // 5) Upload (stream)
            $stream = $disk->readStream($path);
            if (!$stream) {
                throw new \RuntimeException("Read stream failed: {$path}");
            }

            try {
                $resp = $this->robawsClient->uploadDocument($robawsOfferId, [
                    'stream' => $stream,
                    'filename' => $filename,
                    'mime_type' => $mime,
                    'file_size' => $size,
                ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            // 6) Record ledger for idempotency
            RobawsDocument::create([
                'document_id' => $document->id,
                'robaws_offer_id' => $robawsOfferId,
                'robaws_document_id' => $resp['id'] ?? null,
                'sha256' => $sha256,
                'filename' => $filename,
                'filesize' => $size,
            ]);

            Log::info('Robaws upload success', [
                'robaws_offer_id' => $robawsOfferId,
                'sha256' => $sha256,
                'filename' => $filename,
            ]);

            return [
                'status' => 'uploaded',
                'offer_id' => $robawsOfferId,
                'filename' => $filename,
                'robaws_doc_id' => $resp['id'] ?? null,
                'reason' => 'new upload'
            ];
        } finally {
            optional($lock)->release();
        }
    }    /**
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

            // Content-based deduplication: Check if same content already uploaded
            if ($document->source_content_sha) {
                $existingDocument = Document::where('source_content_sha', $document->source_content_sha)
                    ->whereNotNull('robaws_document_id')
                    ->whereNotNull('robaws_quotation_id')
                    ->where('id', '!=', $document->id) // Different document record
                    ->first();

                if ($existingDocument) {
                    Log::info('Content-based duplicate found - reusing existing upload', [
                        'current_document_id' => $document->id,
                        'existing_document_id' => $existingDocument->id,
                        'existing_quotation_id' => $existingDocument->robaws_quotation_id,
                        'existing_robaws_document_id' => $existingDocument->robaws_document_id,
                        'content_sha' => $document->source_content_sha
                    ]);

                    // Update current document to reference the existing upload
                    $document->update([
                        'robaws_quotation_id' => $existingDocument->robaws_quotation_id,
                        'robaws_document_id' => $existingDocument->robaws_document_id,
                        'processing_status' => 'duplicate_content'
                    ]);

                    $extraction->update([
                        'robaws_quotation_id' => $existingDocument->robaws_quotation_id
                    ]);

                    return [
                        'success' => true,
                        'quotation_id' => $existingDocument->robaws_quotation_id,
                        'document_id' => $existingDocument->robaws_document_id,
                        'content_duplicate' => true,
                        'message' => 'Document content already uploaded - reusing existing upload'
                    ];
                }
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

                $uploadResult = $this->uploadDocumentToRobaws($document, $quotationId);
                
                if ($uploadResult['status'] === 'uploaded') {
                    Log::info('Document uploaded successfully', [
                        'document_id' => $document->id,
                        'quotation_id' => $quotationId,
                        'robaws_doc_id' => $uploadResult['robaws_doc_id'] ?? null
                    ]);
                } elseif ($uploadResult['status'] === 'exists') {
                    Log::info('Document already exists in Robaws', [
                        'document_id' => $document->id,
                        'quotation_id' => $quotationId,
                        'reason' => $uploadResult['reason'] ?? 'unknown',
                        'robaws_doc_id' => $uploadResult['robaws_doc_id'] ?? null
                    ]);
                } else {
                    Log::warning('Document upload failed', [
                        'document_id' => $document->id,
                        'quotation_id' => $quotationId,
                        'status' => $uploadResult['status'],
                        'reason' => $uploadResult['reason'] ?? 'unknown'
                    ]);
                }
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
    /**
     * @deprecated Use uploadDocumentToRobaws() instead for idempotent uploads
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
     * Export all documents in an intake to a single Robaws offer
     */
    public function exportIntake(Intake $intake, array $options = []): array
    {
        Log::info('Starting intake export to Robaws', [
            'intake_id' => $intake->id
        ]);

        // 1) Decide target offer - reuse existing or create new
        $offerId = $options['offer_id']
            ?? $intake->robaws_offer_id
            ?? null;

        if (!$offerId && !empty($options['offer_number'])) {
            // Future: implement findOfferByNumber if needed
            Log::info('Offer number specified but not implemented', [
                'offer_number' => $options['offer_number']
            ]);
        }

        if (!$offerId) {
            // Create once, then PERSIST on the intake so future exports reuse it
            Log::info('Creating new Robaws offer for intake', ['intake_id' => $intake->id]);
            
            $created = $this->createOfferFromIntake($intake);
            $offerId = $created['id'] ?? $created['offer_id'];
            
            $intake->update([
                'robaws_offer_id' => $offerId,
                'robaws_offer_number' => $created['number'] ?? $created['offer_number'] ?? null,
            ]);
            
            Log::info('Created and linked new offer to intake', [
                'intake_id' => $intake->id,
                'offer_id' => $offerId,
                'offer_number' => $intake->robaws_offer_number
            ]);
        } else {
            Log::info('Reusing existing offer for intake', [
                'intake_id' => $intake->id,
                'offer_id' => $offerId
            ]);
        }

        // 2) Upload documents with CONTENT-HASH idempotency
        $results = [
            'offer_id' => $offerId,
            'uploaded' => 0,
            'exists' => 0,
            'failed' => 0,
            'errors' => [],
            'details' => []
        ];

        $documents = $intake->documents()
            ->whereHas('extractions', function ($query) {
                $query->where('status', 'completed')
                      ->whereNotNull('extracted_data');
            })
            ->get();

        Log::info('Found documents to export', [
            'intake_id' => $intake->id,
            'document_count' => $documents->count(),
            'offer_id' => $offerId
        ]);

        foreach ($documents as $document) {
            try {
                $outcome = $this->uploadDocumentToRobaws($document, $offerId);
                $results['details'][] = $outcome;

                // Count by status
                if ($outcome['status'] === 'uploaded') {
                    $results['uploaded']++;
                    $this->notify("Uploaded to Robaws offer {$offerId}", $outcome['filename'] ?? 'file', 'success');
                } elseif ($outcome['status'] === 'exists') {
                    $results['exists']++;
                    $this->notify("File already exists in Robaws offer {$offerId}", $outcome['filename'] ?? 'file', 'warning');
                } else {
                    $results['failed']++;
                    $results['errors'][] = $outcome['reason'] ?? 'Unknown error';
                    $this->notify("Robaws upload failed", $outcome['reason'] ?? 'Unknown error', 'danger');
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = $e->getMessage();
                Log::error('Document upload failed', [
                    'document_id' => $document->id,
                    'offer_id' => $offerId,
                    'error' => $e->getMessage()
                ]);
                $this->notify("Upload failed", $e->getMessage(), 'danger');
            }
        }

        Log::info('Intake export completed', [
            'intake_id' => $intake->id,
            'offer_id' => $offerId,
            'uploaded' => $results['uploaded'],
            'exists' => $results['exists'],
            'failed' => $results['failed']
        ]);

        return $results;
    }

    /**
     * Create a single offer from intake's first document's extraction data
     */
    private function createOfferFromIntake(Intake $intake): array
    {
        // Get the first document with extraction data to build the offer
        $document = $intake->documents()
            ->whereHas('extractions', function ($query) {
                $query->where('status', 'completed')
                      ->whereNotNull('extracted_data');
            })
            ->first();

        if (!$document) {
            throw new \RuntimeException("No processed documents found in intake {$intake->id}");
        }

        // Use existing service to create the offer
        $result = $this->robawsService->createOfferFromDocument($document);
        
        if (!$result || !isset($result['id'])) {
            throw new \RuntimeException('Failed to create offer in Robaws');
        }
        
        return $result;
    }

    /**
     * Helper for user-facing notifications
     */
    protected function notify(string $title, ?string $body = null, string $level = 'info'): void
    {
        if (class_exists(\Filament\Notifications\Notification::class)) {
            \Filament\Notifications\Notification::make()
                ->title($title)
                ->body($body)
                ->{$level}()
                ->send();
        } else {
            Log::info($title . ($body ? " — {$body}" : ''));
        }
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

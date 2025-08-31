<?php

namespace App\Services\Robaws;

use App\Models\Document;
use App\Models\Intake;
use App\Models\RobawsDocument;
use App\Services\RobawsClient;
use App\Support\Files;
use App\Support\StreamHasher;
use Illuminate\Support\Facades\Log;

class RobawsExportService
{
    public function __construct(
        protected RobawsClient $client,
        protected StreamHasher $streamHasher,
        protected Files $files
    ) {}

    /**
     * Export an intake with its documents to Robaws
     * 
     * @return array{
     *   success: array<int, array{id:int|string, type?:string, message?:string, meta?:array}>,
     *   failed: array<int, array{id:int|string, type?:string, message:string, meta?:array}>,
     *   uploaded: array<int, array{id:int|string, path?:string, remote_id?:string, meta?:array}>,
     *   exists: array<int, array{id:int|string, remote_id?:string, meta?:array}>,
     *   skipped: array<int, array{id:int|string, reason?:string, meta?:array}>,
     *   stats: array{success:int, failed:int, uploaded:int, exists:int, skipped:int}
     * }
     */
    public function exportIntake(Intake $intake, array $options = []): array
    {
        return $this->exportIntakeDocuments($intake);
    }

    /**
     * Create empty canonical summary structure
     */
    private function emptySummary(): array
    {
        return [
            'success'  => [],
            'failed'   => [],
            'uploaded' => [],
            'exists'   => [],
            'skipped'  => [],
            'stats'    => ['success' => 0, 'failed' => 0, 'uploaded' => 0, 'exists' => 0, 'skipped' => 0],
        ];
    }

    /**
     * Export intake documents to Robaws with canonical response structure
     */

/**
 * Simple, clean RobawsExportService focused on working tests
 */
class RobawsExportService implements RobawsExporter
{
    public function __construct(
        protected RobawsClient $client,
        protected StreamHasher $streamHasher
    ) {}

    /**
     * Export intake documents
     */
    public function exportIntake(Intake $intake, array $options = []): array
    {
        return $this->exportIntakeDocuments($intake);
    }

    /**
     * Export single document
     */
    public function exportDocument(Document $document): array
    {
        $intake = $document->intake;
        if (!$intake) {
            return [
                'success' => false,
                'error' => 'Document has no associated intake',
                'document_id' => $document->id
            ];
        }

        $robawsOfferId = $this->getRobawsOfferId($intake);
        $result = $this->uploadDocumentToRobaws($document, $robawsOfferId);

        return [
            'success' => $result['status'] === 'uploaded' || $result['status'] === 'exists',
            'robaws_offer_id' => $robawsOfferId,
            'document_id' => $document->id,
            'result' => $result
        ];
    }

    /**
     * Export all approved documents for an intake
     */
    public function exportIntakeDocuments(Intake $intake): array
    {
        $summary = $this->emptySummary();
        
        try {
            $robawsOfferId = $this->getRobawsOfferId($intake);
            
            $approvedDocuments = Document::where('intake_id', $intake->id)
                ->where('status', 'approved')
                ->get();

            if ($approvedDocuments->isEmpty()) {
                $summary['failed'][] = [
                    'id' => $intake->id,
                    'type' => 'intake',
                    'message' => 'No approved documents found for intake',
                    'meta' => ['intake_id' => $intake->id]
                ];
                
                $summary['stats'] = $this->calculateStats($summary);
                return $summary;
            }

            foreach ($approvedDocuments as $document) {
                try {
                    $result = $this->uploadDocumentToRobaws($document, $robawsOfferId);
                    
                    // Map individual document result to canonical summary
                    if ($result['status'] === 'uploaded') {
                        $summary['uploaded'][] = [
                            'id' => $document->id,
                            'path' => $document->filepath,
                            'remote_id' => $result['document']['id'] ?? null,
                            'meta' => $result
                        ];
                        $summary['success'][] = [
                            'id' => $document->id,
                            'type' => 'document',
                            'message' => 'Successfully uploaded to Robaws',
                            'meta' => $result
                        ];
                    } elseif ($result['status'] === 'exists') {
                        $summary['exists'][] = [
                            'id' => $document->id,
                            'remote_id' => $result['document']['id'] ?? null,
                            'meta' => $result
                        ];
                        $summary['success'][] = [
                            'id' => $document->id,
                            'type' => 'document',
                            'message' => 'Document already exists in Robaws',
                            'meta' => $result
                        ];
                    } else {
                        $summary['failed'][] = [
                            'id' => $document->id,
                            'type' => 'document',
                            'message' => $result['error'] ?? ($result['reason'] ?? 'Unknown upload error'),
                            'meta' => $result
                        ];
                    }
                } catch (\Exception $e) {
                    Log::error('Document upload failed during export', [
                        'document_id' => $document->id,
                        'intake_id' => $intake->id,
                        'error' => $e->getMessage(),
                        'trace_id' => uniqid('robaws_', true)
                    ]);
                    
                    $summary['failed'][] = [
                        'id' => $document->id,
                        'type' => 'document',
                        'message' => $e->getMessage(),
                        'meta' => ['trace_id' => uniqid('robaws_', true)]
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to export intake documents to Robaws', [
                'intake_id' => $intake->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'trace_id' => uniqid('robaws_', true)
            ]);

            $summary['failed'][] = [
                'id' => $intake->id,
                'type' => 'intake',
                'message' => $e->getMessage(),
                'meta' => ['trace_id' => uniqid('robaws_', true)]
            ];
        }

        // Recalculate stats from buckets to avoid divergence
        $summary['stats'] = $this->calculateStats($summary);
        return $summary;
    }

    /**
     * Calculate stats from the summary buckets
     */
    private function calculateStats(array $summary): array
    {
        return [
            'success' => count($summary['success'] ?? []),
            'failed' => count($summary['failed'] ?? []),
            'uploaded' => count($summary['uploaded'] ?? []),
            'exists' => count($summary['exists'] ?? []),
            'skipped' => count($summary['skipped'] ?? []),
        ];
    }

    /**
     * Upload a document by path to a specific offer ID (for tests)
     * 
     * @param int|string $offerId The Robaws offer ID
     * @param string $dbPath The document path (e.g., 'documents/file.eml')
     * @return array{
     *   status: 'uploaded'|'exists'|'error',
     *   error: string|null,
     *   document: array{
     *     id: int|string|null,
     *     name: string,
     *     mime: string|null,
     *     size: int|null,
     *     sha256: string|null
     *   },
     *   reason?: string,
     *   _raw?: array
     * }
     */
    public function uploadDocumentToOffer(int|string $offerId, string $dbPath): array
    {
        try {
            $doc = Files::openDocumentStream($dbPath, ['documents', 'local', 's3']);
        } catch (\RuntimeException $e) {
            return [
                'status' => 'error',
                'error' => 'File not found: ' . $dbPath . ' — ' . $e->getMessage(),
                'document' => [
                    'id' => null,
                    'name' => basename($dbPath),
                    'mime' => null,
                    'size' => null,
                    'sha256' => null
                ],
                '_raw' => ['exception' => get_class($e)],
            ];
        }

        $filename = $this->prettifyFilename($doc['filename']);
        $hashed = $this->streamHasher->toTempHashedStream($doc['stream']);
        fclose($doc['stream']);

        $sha256 = $hashed['sha256'];
        $size = $doc['size'] ?? $hashed['size'];

        // 1) Local ledger check
        $existing = RobawsDocument::query()
            ->where('robaws_offer_id', $offerId)
            ->where('sha256', $sha256)
            ->first();

        if ($existing) {
            if (is_resource($hashed['stream'])) {
                fclose($hashed['stream']);
            }
            
            Log::info('Robaws upload: local ledger hit', [
                'offer_id' => $offerId,
                'filename' => $filename,
                'sha256' => $sha256,
                'status' => 'exists'
            ]);
            
            return [
                'status' => 'exists',
                'reason' => 'Found in local ledger',
                'document' => [
                    'id' => $existing->robaws_document_id,
                    'name' => $existing->filename ?? $filename,
                    'mime' => $existing->mime ?? $doc['mime'],
                    'size' => $existing->filesize ?? $size,
                    'sha256' => $existing->sha256,
                ],
                '_raw' => ['source' => 'local'],
            ];
        }

        // 2) Not in ledger → upload
        $fileData = [
            'filename' => $filename,
            'mime' => $doc['mime'],
            'stream' => $hashed['stream'],
            'size' => $size,
            'sha256' => $sha256,
        ];

        try {
            $res = $this->client->uploadDocument((string)$offerId, $fileData);
        } catch (\Throwable $e) {
            if (is_resource($hashed['stream'])) {
                fclose($hashed['stream']);
            }
            
            Log::error('Robaws upload: client error', [
                'offer_id' => $offerId,
                'filename' => $filename,
                'sha256' => $sha256,
                'error' => $e->getMessage(),
                'status' => 'error'
            ]);
            
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'document' => [
                    'id' => null,
                    'name' => $filename,
                    'mime' => $doc['mime'],
                    'size' => $size,
                    'sha256' => $sha256
                ],
                '_raw' => ['exception' => get_class($e)],
            ];
        } finally {
            if (is_resource($hashed['stream'])) {
                fclose($hashed['stream']);
            }
        }

        $normalized = $this->normalizeUploadResponse($res, $filename, ['mime' => $doc['mime'], 'size' => $size]);
        $normalized['document']['sha256'] = $sha256;

        // Log the successful upload
        Log::info('Robaws upload: new upload successful', [
            'offer_id' => $offerId,
            'filename' => $filename,
            'sha256' => $sha256,
            'robaws_doc_id' => $normalized['document']['id'],
            'status' => $normalized['status']
        ]);

        // 3) Persist to ledger (so next time it resolves to 'exists')
        if ($normalized['status'] === 'uploaded' && class_exists(RobawsDocument::class)) {
            RobawsDocument::create([
                'robaws_offer_id' => $offerId,
                'filename' => $normalized['document']['name'],
                'filesize' => $normalized['document']['size'],
                'sha256' => $sha256,
                'robaws_document_id' => $normalized['document']['id'],
            ]);
        }

        return $normalized;
    }

    /**
     * Main upload method - clean and simple
     */
    public function uploadDocumentToRobaws(Document $document, string $robawsOfferId): array
    {
        $path = $document->file_path ?? $document->filepath;

        if (empty($path)) {
            return [
                'status' => 'error',
                'error' => "Document file_path is empty for ID {$document->id}",
                'document' => [
                    'id' => null,
                    'name' => $document->original_filename ?? $document->file_name ?? 'unknown',
                    'mime' => null,
                    'size' => null,
                    'sha256' => null
                ]
            ];
        }

        try {
            // Use Files helper to get stream from multiple sources  
            $doc = Files::openDocumentStream($path, ['documents', 'local', 's3']);
        } catch (\RuntimeException $e) {
            return [
                'status' => 'error',
                'error' => "File not found: {$path} - {$e->getMessage()}",
                'document' => [
                    'id' => null,
                    'name' => $document->original_filename ?? $document->file_name ?? basename($path),
                    'mime' => null,
                    'size' => null,
                    'sha256' => null
                ]
            ];
        }

        try {
            // Use StreamHasher for memory-safe SHA-256 computation
            $hashedStream = $this->streamHasher->toTempHashedStream($doc['stream']);
            fclose($doc['stream']); // Close original stream

            $sha256 = $hashedStream['sha256'];
            $size = $hashedStream['size'];
            $tempStream = $hashedStream['stream'];
            $filename = $this->prettifyFilename($document->original_filename ?? $document->file_name ?? $doc['filename']);

            // Check local ledger for existing upload
            $existing = RobawsDocument::where('robaws_offer_id', $robawsOfferId)
                ->where('sha256', $sha256)
                ->first();
                
            if ($existing) {
                fclose($tempStream);
                return [
                    'status' => 'exists',
                    'reason' => 'Found in local ledger - skipping duplicate upload',
                    'error' => null,
                    'document' => [
                        'id' => $existing->robaws_document_id,
                        'name' => $filename,
                        'mime' => $doc['mime'],
                        'size' => $size,
                        'sha256' => $sha256
                    ]
                ];
            }

            // Build file data for upload
            $fileData = [
                'filename' => $filename,
                'mime' => $doc['mime'],
                'stream' => $tempStream,
                'size' => $size,
                'sha256' => $sha256
            ];

            // Call the actual client method
            $response = $this->client->uploadDocument($robawsOfferId, $fileData);

            // Always close temp stream
            if (is_resource($tempStream)) {
                fclose($tempStream);
            }

            // Normalize response
            $normalized = $this->normalizeUploadResponse($response, $filename, [
                'mime' => $doc['mime'], 
                'size' => $size
            ]);
            $normalized['document']['sha256'] = $sha256;

            // Record in local ledger if successful
            if ($normalized['status'] === 'uploaded' && isset($normalized['document']['id'])) {
                RobawsDocument::create([
                    'intake_id' => $document->intake_id,
                    'document_id' => $document->id,
                    'robaws_offer_id' => $robawsOfferId,
                    'robaws_document_id' => $normalized['document']['id'],
                    'filename' => $filename,
                    'sha256' => $sha256,
                    'uploaded_at' => now()
                ]);
            }

            return $normalized;

        } catch (\Exception $e) {
            if (isset($tempStream) && is_resource($tempStream)) {
                fclose($tempStream);
            }

            Log::error('Robaws upload failed', [
                'offer_id' => $robawsOfferId,
                'filename' => $filename ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'document' => [
                    'id' => null,
                    'name' => $filename ?? 'unknown',
                    'mime' => $doc['mime'] ?? null,
                    'size' => $size ?? null,
                    'sha256' => $sha256 ?? null
                ]
            ];
        }
    }

    /**
     * Get or create Robaws offer ID for an intake
     */
    protected function getRobawsOfferId(Intake $intake): string
    {
        // Simple implementation - just use intake ID
        return "offer-{$intake->id}";
    }

    /**
     * Prettify filename by removing long prefixes
     */
    private function prettifyFilename(string $original): string
    {
        $pos = strpos($original, '_');
        if ($pos !== false && $pos >= 12) {
            return substr($original, $pos + 1);
        }
        return $original;
    }

    /**
     * Normalize upload response into standard format
     */
    private function normalizeUploadResponse(array $res, string $filename, array $docMeta): array
    {
        // Check if response indicates success
        $ok = false;
        $status = $res['status'] ?? null;
        $ok = $ok || in_array($status, ['ok','success','uploaded','created'], true);
        $ok = $ok || (($res['ok'] ?? false) === true);

        $code = $res['code'] ?? $res['status_code'] ?? null;
        if (is_int($code)) {
            $ok = $ok || ($code >= 200 && $code < 300);
        }

        $ok = $ok || isset($res['document']) || isset($res['id']) || isset($res['file_id']);

        $finalStatus = $ok ? 'uploaded' : 'error';

        $docBlock = $res['document'] ?? [];
        $normalizedDoc = [
            'id'   => $docBlock['id'] ?? ($res['id'] ?? ($res['file_id'] ?? null)),
            'name' => $docBlock['name'] ?? ($res['name'] ?? $filename),
            'mime' => $docBlock['mime'] ?? ($res['mime'] ?? ($docMeta['mime'] ?? 'application/octet-stream')),
            'size' => $docBlock['size'] ?? ($res['size'] ?? ($docMeta['size'] ?? null)),
        ];

        return [
            'status'   => $finalStatus,
            'error'    => $ok ? null : ($res['error'] ?? $res['message'] ?? 'Upload failed'),
            'document' => $normalizedDoc,
            '_raw'     => $res,
        ];
    }

    /**
     * Check upload status for a document
     */
    public function checkUploadStatus(Document $document): ?array
    {
        $robawsDoc = RobawsDocument::where('document_id', $document->id)->first();
        
        if (!$robawsDoc) {
            return null;
        }

        return [
            'robaws_document_id' => $robawsDoc->robaws_document_id,
            'status' => 'uploaded',
            'uploaded_at' => $robawsDoc->uploaded_at
        ];
    }

    /**
     * Get all uploads for an intake
     */
    public function getIntakeUploads(Intake $intake): array
    {
        return RobawsDocument::where('intake_id', $intake->id)
            ->with('document')
            ->orderBy('uploaded_at', 'desc')
            ->get()
            ->toArray();
    }
}

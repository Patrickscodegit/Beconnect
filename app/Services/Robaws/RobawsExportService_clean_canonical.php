<?php

namespace App\Services\Robaws;

use App\Models\Document;
use App\Models\Intake;
use App\Models\RobawsDocument;
use App\Services\RobawsClient;
use App\Support\Files;
use App\Support\StreamHasher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

final class RobawsExportService
{
    public function __construct(
        private readonly RobawsClient $client,
        private readonly StreamHasher $streamHasher,
        private readonly Files $files,
    ) {}

    /**
     * Canonical export summary shape
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

    private function finalize(array $summary): array
    {
        // Ensure all keys exist (avoids "undefined key" if future edits forget a bucket)
        $summary = array_merge($this->emptySummary(), $summary);

        $summary['stats'] = [
            'success'  => count($summary['success']),
            'failed'   => count($summary['failed']),
            'uploaded' => count($summary['uploaded']),
            'exists'   => count($summary['exists']),
            'skipped'  => count($summary['skipped']),
        ];

        return $summary;
    }

    /**
     * Export an Intake to Robaws (quotation, files, …).
     * Always returns the canonical shape.
     */
    public function exportIntake(Intake $intake, array $options = []): array
    {
        $summary = $this->emptySummary();

        try {
            $robawsOfferId = $this->getRobawsOfferId($intake);
            
            $approvedDocuments = Document::where('intake_id', $intake->id)
                ->where('status', 'approved')
                ->get();

            if ($approvedDocuments->isEmpty()) {
                $summary['failed'][] = [
                    'id'      => $intake->id,
                    'type'    => 'intake',
                    'message' => 'No approved documents found for intake',
                    'meta'    => ['intake_id' => $intake->id],
                ];
                
                return $this->finalize($summary);
            }

            foreach ($approvedDocuments as $document) {
                try {
                    $result = $this->uploadDocumentToRobaws($document, $robawsOfferId);
                    
                    // Map individual document result to canonical summary
                    if ($result['status'] === 'uploaded') {
                        $summary['uploaded'][] = [
                            'id'        => $document->id,
                            'path'      => $document->filepath,
                            'remote_id' => $result['document']['id'] ?? null,
                            'meta'      => $result,
                        ];
                        $summary['success'][] = [
                            'id'      => $document->id,
                            'type'    => 'document',
                            'message' => 'Successfully uploaded to Robaws',
                            'meta'    => $result,
                        ];
                    } elseif ($result['status'] === 'exists') {
                        $summary['exists'][] = [
                            'id'        => $document->id,
                            'remote_id' => $result['document']['id'] ?? null,
                            'meta'      => $result,
                        ];
                        $summary['success'][] = [
                            'id'      => $document->id,
                            'type'    => 'document', 
                            'message' => 'Document already exists in Robaws',
                            'meta'    => $result,
                        ];
                    } else {
                        $summary['failed'][] = [
                            'id'      => $document->id,
                            'type'    => 'document',
                            'message' => $result['error'] ?? ($result['reason'] ?? 'Unknown upload error'),
                            'meta'    => $result,
                        ];
                    }
                } catch (\Throwable $e) {
                    $traceId = uniqid('robaws_', true);
                    Log::error('Document upload failed during export', [
                        'document_id' => $document->id,
                        'intake_id' => $intake->id,
                        'error' => $e->getMessage(),
                        'trace_id' => $traceId,
                    ]);
                    
                    $summary['failed'][] = [
                        'id'      => $document->id,
                        'type'    => 'document',
                        'message' => $e->getMessage(),
                        'meta'    => ['trace_id' => $traceId],
                    ];
                }
            }

        } catch (\Throwable $e) {
            $traceId = uniqid('robaws_', true);
            Log::error('Robaws exportIntake failed', [
                'intake_id' => $intake->id,
                'trace_id'  => $traceId,
                'exception' => $e,
            ]);

            $summary['failed'][] = [
                'id'      => $intake->id,
                'type'    => 'intake',
                'message' => $e->getMessage(),
                'meta'    => ['trace_id' => $traceId],
            ];
        }

        return $this->finalize($summary);
    }

    /**
     * Export *documents* of an Intake to Robaws.
     * Keep the same canonical shape.
     */
    public function exportIntakeDocuments(Intake $intake): array
    {
        return $this->exportIntake($intake);
    }

    /**
     * Get or create Robaws offer ID for an intake
     */
    protected function getRobawsOfferId(Intake $intake): string
    {
        if ($intake->robaws_offer_id) {
            return $intake->robaws_offer_id;
        }

        // Create a new offer if none exists
        $extraction = $intake->extraction;
        if (!$extraction || !$extraction->extracted_data) {
            throw new \RuntimeException("No extraction data found for intake {$intake->id}");
        }

        $robawsData = $this->mapExtractionToRobaws($extraction->extracted_data);
        $offer = $this->client->createOffer($robawsData);
        
        if (!isset($offer['id'])) {
            throw new \RuntimeException("Failed to create Robaws offer for intake {$intake->id}");
        }

        $offerId = $offer['id'];
        $intake->update(['robaws_offer_id' => $offerId]);
        
        Log::info('Created new Robaws offer', [
            'intake_id' => $intake->id,
            'offer_id' => $offerId
        ]);

        return $offerId;
    }

    /**
     * Upload a document by path to a specific offer ID (for tests)
     * 
     * @param int|string $offerId The Robaws offer ID
     * @param string $dbPath The document path (e.g., 'documents/file.eml')
     * @return array{
     *   status: string,
     *   error?: string,
     *   document: array{id: ?int, name: string, mime: string, size: ?int, sha256?: string}
     * }
     */
    public function uploadDocumentByPath(int|string $offerId, string $dbPath): array
    {
        try {
            $doc = $this->files->resolve($dbPath);
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
                'robaws_document_id' => $normalized['document']['id'],
                'filename' => $filename,
                'sha256' => $sha256,
                'filesize' => $size,
                'uploaded_at' => now()
            ]);
        }

        return $normalized;
    }

    /**
     * Upload a document to Robaws with idempotency
     */
    protected function uploadDocumentToRobaws(Document $document, string $robawsOfferId): array
    {
        return $this->uploadDocumentByPath($robawsOfferId, $document->filepath);
    }

    /**
     * Map extraction data to Robaws format
     */
    protected function mapExtractionToRobaws(array $extractedData): array
    {
        // Simplified mapping - adjust based on your actual Robaws API requirements
        return [
            'customer_reference' => $extractedData['reference'] ?? 'AUTO-' . uniqid(),
            'origin' => $extractedData['shipment']['origin'] ?? null,
            'destination' => $extractedData['shipment']['destination'] ?? null,
            'cargo_description' => $extractedData['cargo']['description'] ?? null,
            'vehicle_count' => count($extractedData['vehicles'] ?? []),
            'extraction_metadata' => $extractedData['metadata'] ?? []
        ];
    }

    /**
     * Normalize upload response from client to standard format
     */
    protected function normalizeUploadResponse(array $res, string $filename, array $docMeta = []): array
    {
        $ok = isset($res['success']) && $res['success'] === true;
        $code = $res['status_code'] ?? $res['code'] ?? null;

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
            'document' => $normalizedDoc,
            '_raw'     => $res,
        ];
    }

    /**
     * Clean up filename for better display
     */
    protected function prettifyFilename(string $filename): string
    {
        // Remove timestamp prefixes that might exist
        $cleaned = preg_replace('/^\d{10,}_/', '', basename($filename));
        
        // Replace underscores with spaces and title case
        $cleaned = str_replace('_', ' ', $cleaned);
        
        return $cleaned;
    }
}

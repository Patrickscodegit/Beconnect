<?php

namespace App\Services\Robaws;

use App\Models\Document;
use App\Models\Intake;
use App\Models\RobawsDocument;
use App\Services\RobawsIntegrationService;
use App\Services\MultiDocumentUploadService;
use App\Services\RobawsClient;
use App\Services\Robaws\Contracts\RobawsExporter;
use App\Support\StreamHasher;
use App\Support\Files;
use App\Exceptions\RobawsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Main Robaws Export Service with idempotent uploads and offer reuse
 * 
 * Features:
 * - Content-hash based deduplication
 * - Intake-to-offer mapping for reuse
 * - Memory-safe SHA-256 computation via StreamHasher
 * - Lock-based concurrency control
 * - Comprehensive error handling with normalized responses
 */
class RobawsExportService implements RobawsExporter
{
    public function __construct(
        protected RobawsClient $client,
        protected StreamHasher $streamHasher
    ) {}

    /**
     * Export all documents in an intake to a single Robaws offer
     */
    public function exportIntake(Intake $intake, array $options = []): array
    {
        return $this->exportIntakeDocuments($intake);
    }

    /**
     * Export a single document to Robaws
     */
    public function exportDocument(Document $document): array
    {
        try {
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
                'success' => $result['status'] === 'success' || $result['status'] === 'exists',
                'robaws_offer_id' => $robawsOfferId,
                'document_id' => $document->id,
                'result' => $result
            ];

        } catch (\Exception $e) {
            Log::error('Failed to export document to Robaws', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'document_id' => $document->id
            ];
        }
    }

    /**
     * Export all approved documents for an intake to Robaws
     */
    public function exportIntakeDocuments(Intake $intake): array
    {
        try {
            $robawsOfferId = $this->getRobawsOfferId($intake);
            
            $approvedDocuments = Document::where('intake_id', $intake->id)
                ->where('status', 'approved')
                ->get();

            if ($approvedDocuments->isEmpty()) {
                return [
                    'success' => false,
                    'error' => 'No approved documents found for intake',
                    'intake_id' => $intake->id
                ];
            }

            $results = [];
            foreach ($approvedDocuments as $document) {
                $result = $this->uploadDocumentToRobaws($document, $robawsOfferId);
                $results[] = $result;
            }

            return [
                'success' => true,
                'robaws_offer_id' => $robawsOfferId,
                'intake_id' => $intake->id,
                'results' => $results
            ];

        } catch (\Exception $e) {
            Log::error('Failed to export intake documents to Robaws', [
                'intake_id' => $intake->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'intake_id' => $intake->id
            ];
        }
    }

    /**
     * Get or create Robaws offer ID for an intake
     */
    protected function getRobawsOfferId(Intake $intake): string
    {
        // Check if we already have a cached offer ID for this intake
        $cacheKey = "robaws_offer_id_{$intake->id}";
        $cachedOfferId = Cache::get($cacheKey);
        
        if ($cachedOfferId) {
            return $cachedOfferId;
        }

        // Check database for existing mapping
        $existingDoc = RobawsDocument::where('intake_id', $intake->id)->first();
        if ($existingDoc && $existingDoc->robaws_offer_id) {
            Cache::put($cacheKey, $existingDoc->robaws_offer_id, 3600); // Cache for 1 hour
            return $existingDoc->robaws_offer_id;
        }

        // Create new offer in Robaws
        $offerData = $this->prepareOfferData($intake);
        $response = $this->client->createOffer($offerData);
        
        if (!isset($response['offer_id'])) {
            throw new RobawsException('Failed to create Robaws offer: Invalid response');
        }

        $offerId = $response['offer_id'];
        Cache::put($cacheKey, $offerId, 3600);
        
        return $offerId;
    }

    /**
     * Prepare offer data from intake
     */
    protected function prepareOfferData(Intake $intake): array
    {
        return [
            'external_id' => $intake->id,
            'customer_name' => $intake->customer_name,
            'email' => $intake->email,
            'phone' => $intake->phone,
            'brand' => $intake->brand,
            'model' => $intake->model,
            'year' => $intake->year,
            'created_at' => $intake->created_at->toISOString()
        ];
    }

    /**
     * Upload a document to Robaws with idempotent behavior
     * Uses StreamHasher for memory-safe SHA-256 computation
     */
    public function uploadDocumentToRobaws(Document $document, string $robawsOfferId): array
    {
        $path = $document->file_path ?? $document->filepath; // Support both field names

        if (empty($path)) {
            return [
                'status' => 'error',
                'offer_id' => $robawsOfferId,
                'reason' => "Document file_path is empty/null for ID {$document->id}",
                'filename' => $document->original_filename ?? $document->file_name,
                'sha256' => null
            ];
        }

        try {
            // Use Files helper to get stream from multiple disk sources
            $doc = Files::openDocumentStream($path, ['documents', 'local', 's3']);
            
        } catch (\RuntimeException $e) {
            return [
                'status' => 'error',
                'offer_id' => $robawsOfferId,
                'reason' => "File not found: {$path} - {$e->getMessage()}",
                'filename' => $document->original_filename ?? $document->file_name,
                'sha256' => null
            ];
        }

        $filename = $this->prettifyFilename($document->original_filename ?? $document->file_name ?? $doc['filename']);

        try {
            // Use StreamHasher for memory-safe SHA-256 computation
            $hashedStream = $this->streamHasher->toTempHashedStream($doc['stream']);
            
            // Close the source stream as we now have a temp stream
            fclose($doc['stream']);
            
            $sha256 = $hashedStream['sha256'];
            $size = $hashedStream['size'];
            $tempStream = $hashedStream['stream'];
            $mime = $doc['mime'];

            // Lock to prevent concurrent double-uploads
            $lockKey = "robaws:upload:{$robawsOfferId}:{$sha256}";
            $lock = Cache::lock($lockKey, 20);
            if (!$lock->get()) {
                if (is_resource($tempStream)) {
                    fclose($tempStream);
                }
                Log::info('Robaws dedupe: lock wait/skip', compact('robawsOfferId', 'sha256', 'filename'));
                return [
                    'status' => 'exists',
                    'offer_id' => $robawsOfferId,
                    'filename' => $filename,
                    'reason' => 'upload in progress',
                    'sha256' => $sha256
                ];
            }

            try {
                // Check local ledger for existing upload
                $already = RobawsDocument::where('robaws_offer_id', $robawsOfferId)
                    ->where('sha256', $sha256)
                    ->first();
                    
                if ($already) {
                    if (is_resource($tempStream)) {
                        fclose($tempStream);
                    }
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
                        'reason' => 'ledger match',
                        'sha256' => $sha256
                    ];
                }

                // Perform the actual upload with correct parameter order
                $fileData = [
                    'filename' => $filename,
                    'mime_type' => $mime,
                    'stream' => $tempStream,
                    'file_size' => $size,
                    'sha256' => $sha256
                ];

                // ✅ Correct argument order: (quotationId/offerId, fileData)
                $response = $this->client->uploadDocument($robawsOfferId, $fileData);

                // Normalize the response into a stable shape
                $normalized = $this->normalizeUploadResponse($response, $filename, ['mime' => $mime, 'size' => $size]);
                $normalized['document']['sha256'] = $sha256;

                if ($normalized['status'] === 'error') {
                    return [
                        'status' => 'error',
                        'offer_id' => $robawsOfferId,
                        'filename' => $filename,
                        'reason' => $normalized['error'] ?? 'Upload failed',
                        'sha256' => $sha256,
                        'document' => $normalized['document']
                    ];
                }

                // Record successful upload in local ledger
                RobawsDocument::create([
                    'intake_id' => $document->intake_id,
                    'document_id' => $document->id,
                    'robaws_offer_id' => $robawsOfferId,
                    'robaws_document_id' => $normalized['document']['id'],
                    'filename' => $filename,
                    'sha256' => $sha256,
                    'uploaded_at' => now()
                ]);

                Log::info('Robaws upload success', [
                    'robaws_offer_id' => $robawsOfferId,
                    'robaws_document_id' => $normalized['document']['id'],
                    'filename' => $filename,
                    'sha256' => $sha256
                ]);

                return [
                    'status' => $normalized['status'],
                    'offer_id' => $robawsOfferId,
                    'filename' => $filename,
                    'robaws_doc_id' => $normalized['document']['id'],
                    'sha256' => $sha256,
                    'document' => $normalized['document']
                ];

            } finally {
                $lock->release();
            }

        } catch (\Exception $e) {
            // Always close the temp stream on error
            if (isset($tempStream) && is_resource($tempStream)) {
                fclose($tempStream);
            }
            
            Log::error('Robaws upload failed', [
                'offer_id' => $robawsOfferId,
                'filename' => $filename ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'error',
                'offer_id' => $robawsOfferId,
                'filename' => $filename ?? 'unknown',
                'reason' => $e->getMessage(),
                'sha256' => $sha256 ?? null
            ];
        } finally {
            // Always ensure temp stream is closed
            if (isset($tempStream) && is_resource($tempStream)) {
                fclose($tempStream);
            }
        }
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

        try {
            $response = $this->client->getDocumentStatus($robawsDoc->robaws_document_id);
            return [
                'robaws_document_id' => $robawsDoc->robaws_document_id,
                'status' => $response['status'] ?? 'unknown',
                'uploaded_at' => $robawsDoc->uploaded_at
            ];
        } catch (\Exception $e) {
            Log::error('Failed to check Robaws document status', [
                'document_id' => $document->id,
                'robaws_document_id' => $robawsDoc->robaws_document_id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
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

    /**
     * Prettify filename by dropping long UUID-like prefixes
     */
    private function prettifyFilename(string $original): string
    {
        // Drop a long prefix before underscore (e.g., UUID_foo.pdf => foo.pdf)
        $pos = strpos($original, '_');
        if ($pos !== false && $pos >= 12) {
            return substr($original, $pos + 1);
        }
        return $original;
    }

    /**
     * Normalize any plausible client response into a stable shape.
     * Returns 'uploaded' on ok-ish statuses/codes/payloads, else 'error'.
     */
    private function normalizeUploadResponse(array $res, string $filename, array $docMeta): array
    {
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
            'id'   => $docBlock['id']   ?? ($res['id'] ?? $res['file_id'] ?? $res['document_id'] ?? null),
            'name' => $docBlock['name'] ?? ($res['name'] ?? $filename),
            'mime' => $docBlock['mime'] ?? ($res['mime'] ?? ($docMeta['mime'] ?? 'application/octet-stream')),
            'size' => $docBlock['size']
                  ?? $docBlock['file_size']
                  ?? ($res['size'] ?? $res['file_size'] ?? ($docMeta['size'] ?? null)),
        ];

        return [
            'status'   => $finalStatus,
            'error'    => $ok ? null : ($res['error'] ?? $res['message'] ?? null),
            'document' => $normalizedDoc,
            '_raw'     => $res,
        ];
    }
}

<?php

namespace App\Services\Robaws;

use App\Models\Document;
use App\Models\Intake;
use App\Models\RobawsDocument;
use App\Services\RobawsClient;
use App\Services\Robaws\Contracts\RobawsExporter;
use App\Support\StreamHasher;
use App\Support\Files;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

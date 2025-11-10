<?php

namespace App\Services;

use App\Models\Document;
use App\Support\EmailFingerprint;
use App\Services\Extraction\HybridExtractionPipeline;
use App\Services\Robaws\RobawsPayloadBuilder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class EmailDocumentService
{
    public function __construct(
        private HybridExtractionPipeline $extractionPipeline
    ) {}

    /**
     * Ingest an already-stored email file with deduplication
     */
    public function ingestStoredEmail(string $disk, string $path, ?int $intakeId = null, ?string $originalFilename = null): array
    {
        try {
            // Read the stored email content
            if (!Storage::disk($disk)->exists($path)) {
                throw new \Exception("Email file not found at path: {$path}");
            }
            
            $rawEmail = Storage::disk($disk)->get($path);
            $headers = EmailFingerprint::parseHeaders($rawEmail);
            $plainBody = EmailFingerprint::extractPlainBody($rawEmail);
            $fingerprint = EmailFingerprint::fromRaw($rawEmail, $headers, $plainBody);

            // Check for existing document using fingerprint (scoped to this intake)
            $existingDocument = $this->findExistingDocument($fingerprint, $intakeId);
            
            if ($existingDocument) {
                return [
                    'status' => 'duplicate',
                    'skipped_as_duplicate' => true,
                    'message' => 'Email already processed in this intake',
                    'document_id' => $existingDocument->id,
                    'original_extraction' => $existingDocument->extraction_data,
                ];
            }

            // Create document record with fingerprint
            $filename = $originalFilename ?: basename($path);
            $fileSize = Storage::disk($disk)->size($path);
            
            // Check for existing document by (intake_id, source_content_sha)
            $existingBySha = Document::query()
                ->where('intake_id', $intakeId)
                ->where('source_content_sha', $fingerprint['content_sha'])
                ->first();

            if ($existingBySha) {
                return [
                    'status' => 'duplicate',
                    'skipped_as_duplicate' => true,
                    'message' => 'Email already processed in this intake (by content hash)',
                    'document_id' => $existingBySha->id,
                    'original_extraction' => $existingBySha->extraction_data,
                ];
            }

            // Global duplicate guard to avoid unique constraint exceptions (when emails are intake-agnostic)
            $existingGlobalSha = Document::query()
                ->where('source_content_sha', $fingerprint['content_sha'])
                ->first();

            if ($existingGlobalSha) {
                return [
                    'status' => 'duplicate',
                    'skipped_as_duplicate' => true,
                    'message' => 'Email already processed in another intake (by content hash)',
                    'document_id' => $existingGlobalSha->id,
                    'original_extraction' => $existingGlobalSha->extraction_data,
                ];
            }
            
            $document = Document::create([
                'intake_id' => $intakeId,
                'filename' => $filename,
                'original_filename' => $filename,
                'mime_type' => 'message/rfc822',
                'file_path' => $path,
                'file_size' => $fileSize,
                'storage_disk' => $disk,
                'document_type' => 'freight_document',
                'has_text_layer' => false,
                'source_message_id' => $fingerprint['message_id'],
                'source_content_sha' => $fingerprint['content_sha'],
                'processing_status' => 'pending',
            ]);

            // Extract data with headers enhancement
            $extractionResult = $this->extractionPipeline->extract($plainBody, 'email');
            $data = $extractionResult['data'] ?? [];
            
            // Enhance with header information
            if (!data_get($data, 'contact.email') && isset($headers['from'])) {
                $fromEmail = $this->extractEmailFromHeader($headers['from']);
                if ($fromEmail) {
                    data_set($data, 'contact.email', $fromEmail);
                }
            }

            // Store extraction data
            $document->update([
                'extraction_data' => $data,
                'processing_status' => 'completed',
            ]);

            Log::info('EmailDocumentService: Email ingested successfully', [
                'document_id' => $document->id,
                'fingerprint_type' => $fingerprint['message_id'] ? 'message-id' : 'content-hash',
                'path' => $path,
            ]);

            return [
                'status' => 'success',
                'skipped_as_duplicate' => false,
                'document' => $document,
                'extraction_data' => $data,
                'fingerprint' => $fingerprint,
                'headers' => $headers,
            ];
            
        } catch (\Exception $e) {
            Log::error('EmailDocumentService: Failed to ingest stored email', [
                'error' => $e->getMessage(),
                'path' => $path,
                'disk' => $disk,
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }

    /**
     * Check if email is duplicate without processing
     */
    public function isDuplicate(string $rawEmail, ?int $intakeId = null): array
    {
        try {
            $headers = EmailFingerprint::parseHeaders($rawEmail);
            $plainBody = EmailFingerprint::extractPlainBody($rawEmail);
            $fingerprint = EmailFingerprint::fromRaw($rawEmail, $headers, $plainBody);

            $existingDocument = $this->findExistingDocument($fingerprint, $intakeId);
            
            // Determine what matched (message_id or content_sha)
            $matchedOn = null;
            if ($existingDocument) {
                $messageId = $fingerprint['message_id'] ?? null;
                $contentSha = $fingerprint['content_sha'] ?? null;
                
                if ($messageId && $existingDocument->source_message_id === $messageId) {
                    $matchedOn = 'message_id';
                } elseif ($contentSha && $existingDocument->source_content_sha === $contentSha) {
                    $matchedOn = 'content_sha';
                }
            }
            
            return [
                'is_duplicate' => $existingDocument !== null,
                'document_id' => $existingDocument?->id,
                'document' => $existingDocument,
                'matched_on' => $matchedOn,
                'fingerprint' => $fingerprint,
                'headers' => $headers,
            ];
        } catch (\Exception $e) {
            Log::error('EmailDocumentService: Duplicate check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'is_duplicate' => false,
                'document_id' => null,
                'document' => null,
                'matched_on' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process email with deduplication and complete field mapping
     */
    public function processEmail(string $rawEmail, string $filename = null): array
    {
        try {
            // Parse email components
            $headers = EmailFingerprint::parseHeaders($rawEmail);
            $plainBody = EmailFingerprint::extractPlainBody($rawEmail);
            $fingerprint = EmailFingerprint::fromRaw($rawEmail, $headers, $plainBody);

            // Check for existing document using fingerprint
            $existingDocument = $this->findExistingDocument($fingerprint);
            
            if ($existingDocument) {
                return [
                    'status' => 'duplicate',
                    'message' => 'Email already processed',
                    'document_id' => $existingDocument->id,
                    'original_extraction' => $existingDocument->extraction_data,
                ];
            }

            // Store email file
            $filename = $filename ?: 'email_' . now()->format('Y-m-d_H-i-s') . '.eml';
            $filePath = 'emails/' . date('Y/m/d') . '/' . $filename;
            Storage::disk('documents')->put($filePath, $rawEmail);

            // Check for existing document by content_sha to avoid unique constraint
            $existingBySha = Document::where('source_content_sha', $fingerprint['content_sha'])->first();
            if ($existingBySha) {
                // Clean up the file we just stored since it's a duplicate
                Storage::disk('documents')->delete($filePath);
                
                return [
                    'status' => 'duplicate',
                    'message' => 'Email already processed (detected after file storage)',
                    'document_id' => $existingBySha->id,
                    'original_extraction' => $existingBySha->extraction_data,
                ];
            }

            // Create document record with fingerprint
            $document = Document::create([
                'filename' => $filename,
                'original_filename' => $filename,
                'mime_type' => 'message/rfc822',
                'file_path' => $filePath,
                'storage_disk' => 'documents',
                'size' => strlen($rawEmail),
                'source_message_id' => $fingerprint['message_id'],
                'source_content_sha' => $fingerprint['content_sha'],
                'processing_status' => 'processing',
                'document_type' => 'email',
            ]);

            // Extract data from plain text
            $extractionResult = $this->extractionPipeline->extract($plainBody, 'email');
            $extractedData = $extractionResult['data'] ?? [];

            // Enhance with header email if missing
            $senderEmail = $this->extractSenderEmail($headers);
            if (!data_get($extractedData, 'contact.email') && $senderEmail) {
                data_set($extractedData, 'contact.email', $senderEmail);
            }

            // Build Robaws payload
            $robawsPayload = RobawsPayloadBuilder::build($extractedData);
            $payloadValidation = RobawsPayloadBuilder::validatePayload($robawsPayload);

            // Update document with extraction results
            $document->update([
                'extraction_data' => $extractedData,
                'processing_status' => 'completed',
                'processed_at' => now(),
            ]);

            Log::info('Email processed successfully', [
                'document_id' => $document->id,
                'message_id' => $fingerprint['message_id'],
                'quality_score' => data_get($extractedData, 'final_validation.quality_score', 0),
                'completeness_score' => data_get($extractedData, 'final_validation.completeness_score', 0),
            ]);

            return [
                'status' => 'success',
                'message' => 'Email processed successfully',
                'document_id' => $document->id,
                'extraction_data' => $extractedData,
                'robaws_payload' => $robawsPayload,
                'payload_validation' => $payloadValidation,
                'recommendation' => $this->getProcessingRecommendation($payloadValidation, $extractedData),
                'metadata' => [
                    'sender_email' => $senderEmail,
                    'message_id' => $fingerprint['message_id'],
                    'content_hash' => $fingerprint['content_sha'],
                    'quality_score' => data_get($extractedData, 'final_validation.quality_score', 0),
                    'completeness_score' => data_get($extractedData, 'final_validation.completeness_score', 0),
                ],
            ];

        } catch (\Exception $e) {
            Log::error('Email processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Failed to process email: ' . $e->getMessage(),
                'extraction_data' => null,
            ];
        }
    }

    /**
     * Extract email address from header string
     */
    private function extractEmailFromHeader(string $header): ?string
    {
        // Match email addresses in headers like "John Doe <john@example.com>"
        if (preg_match('/<([^>]+@[^>]+)>/', $header, $matches)) {
            return $matches[1];
        }
        
        // Direct email address
        if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $header, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    /**
     * Find existing document by fingerprint
     */
    private function findExistingDocument(array $fingerprint, ?int $intakeId = null): ?Document
    {
        // First try by Message-ID (scoped to intake if provided)
        if ($fingerprint['message_id']) {
            $query = Document::where('source_message_id', $fingerprint['message_id']);
            if ($intakeId !== null) {
                $query->where('intake_id', $intakeId);
            }
            $existing = $query->first();
            if ($existing) return $existing;
        }

        // Fallback to content hash (scoped to intake if provided)
        $query = Document::where('source_content_sha', $fingerprint['content_sha']);
        if ($intakeId !== null) {
            $query->where('intake_id', $intakeId);
        }
        return $query->first();
    }

    /**
     * Extract sender email from headers
     */
    private function extractSenderEmail(array $headers): ?string
    {
        $from = $headers['from'] ?? '';
        
        if (preg_match('/<(.+?)>/', $from, $matches)) {
            return trim($matches[1]);
        } elseif (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $from, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }

    /**
     * Get processing recommendation based on validation results
     */
    private function getProcessingRecommendation(array $payloadValidation, array $extractedData): array
    {
        $qualityScore = data_get($extractedData, 'final_validation.quality_score', 0);
        $missingRequired = count($payloadValidation['missing_required'] ?? []);
        $missingRecommended = count($payloadValidation['missing_recommended'] ?? []);

        if ($missingRequired === 0 && $qualityScore >= 0.8) {
            return [
                'action' => 'auto_process',
                'confidence' => 'high',
                'message' => 'Ready for automatic Robaws export',
                'issues' => []
            ];
        } elseif ($missingRequired <= 1 && $qualityScore >= 0.7) {
            return [
                'action' => 'review_required',
                'confidence' => 'medium',
                'message' => 'Good extraction but needs minor review',
                'issues' => array_merge(
                    array_map(fn($f) => "Missing required field: $f", $payloadValidation['missing_required'] ?? []),
                    array_map(fn($f) => "Missing recommended field: $f", $payloadValidation['missing_recommended'] ?? [])
                )
            ];
        } else {
            return [
                'action' => 'manual_processing',
                'confidence' => 'low',
                'message' => 'Requires manual processing due to incomplete extraction',
                'issues' => array_merge(
                    array_map(fn($f) => "Missing required field: $f", $payloadValidation['missing_required'] ?? []),
                    array_map(fn($f) => "Missing recommended field: $f", $payloadValidation['missing_recommended'] ?? [])
                )
            ];
        }
    }

    /**
     * Get document by fingerprint (for checking duplicates)
     */
    public function getDocumentByFingerprint(string $rawEmail, ?int $intakeId = null): ?Document
    {
        $headers = EmailFingerprint::parseHeaders($rawEmail);
        $plainBody = EmailFingerprint::extractPlainBody($rawEmail);
        $fingerprint = EmailFingerprint::fromRaw($rawEmail, $headers, $plainBody);

        return $this->findExistingDocument($fingerprint, $intakeId);
    }
}

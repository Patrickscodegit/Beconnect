<?php

namespace App\Services\Robaws;

use App\Models\Intake;
use App\Models\RobawsDocument;
use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Export\Clients\RobawsApiClient;
use App\Services\RobawsClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RobawsExportService
{
    private RobawsMapper $mapper;
    private RobawsApiClient $apiClient;
    private ?RobawsClient $legacyClient = null;

    public function __construct(
        RobawsMapper $mapper,
        RobawsApiClient $apiClient,
        ?RobawsClient $legacyClient = null
    ) {
        $this->mapper = $mapper;
        $this->apiClient = $apiClient;
        $this->legacyClient = $legacyClient;
    }

    /**
     * Prefer injected, but always re-check the container in case tests bind later.
     */
    private function legacy(): ?RobawsClient
    {
        if ($this->legacyClient instanceof RobawsClient) {
            return $this->legacyClient;
        }
        return app()->bound(RobawsClient::class) ? app(RobawsClient::class) : null;
    }

    /**
     * Export intake to Robaws with comprehensive error handling
     */
    public function exportIntake(Intake $intake, array $options = []): array
    {
        $startTime = microtime(true);
        $exportId = uniqid('export_', true);
        
        try {
            Log::info('Starting Robaws export', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'customer' => $intake->customer_name,
                'options' => $options,
            ]);

            // Ensure relations are loaded
            $intake->loadMissing(['extraction', 'documents.extraction', 'documents.extractions']);

            // Check if already exported recently (unless forced)
            if (!($options['force'] ?? false) && $this->wasRecentlyExported($intake)) {
                return [
                    'success' => false,
                    'error' => 'Intake was already exported recently. Use force=true to re-export.',
                    'quotation_id' => $intake->robaws_quotation_id,
                ];
            }

            // Get extraction data
            $extractionData = $this->getExtractionData($intake);
            
            Log::info('Extraction data summary', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'has_vehicle' => !empty($extractionData['vehicle']),
                'has_shipping' => !empty($extractionData['shipping']),
                'has_contact' => !empty($extractionData['contact']),
                'data_size' => strlen(json_encode($extractionData)),
            ]);

            // Resolve client ID before mapping
            $customerName = data_get($extractionData, 'document_data.contact.name')
                         ?? data_get($extractionData, 'contact.name')
                         ?? $intake->customer_name
                         ?? null;
            $customerEmail = data_get($extractionData, 'document_data.contact.email')
                         ?? data_get($extractionData, 'contact.email')
                         ?? $intake->customer_email
                         ?? null;

            // Resolve Robaws client
            $clientId = $this->apiClient->findClientId($customerName, $customerEmail);
            
            Log::info('Robaws client resolution', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'name' => $customerName,
                'email' => $customerEmail,
                'clientId' => $clientId,
                'binding_status' => $clientId ? 'will_bind_to_client' : 'no_client_binding',
            ]);

            // Map to Robaws format
            $mapped = $this->mapper->mapIntakeToRobaws($intake, $extractionData);
            
            // SAFEGUARD: Only inject clientId if we found a confident match
            if ($clientId) {
                $mapped['client_id'] = $clientId;  // Critical for binding the UI Customer
                Log::info('Client ID injected into payload', [
                    'export_id' => $exportId,
                    'client_id' => $clientId,
                    'customer_name' => $customerName,
                ]);
            } else {
                Log::warning('No unique client match found - export will proceed without client binding', [
                    'export_id' => $exportId,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                ]);
            }
            
            if ($customerEmail) {
                $mapped['quotation_info']['contact_email'] = $customerEmail;
            }
            
            // Build and log final payload for debugging
            $payload = $this->mapper->toRobawsApiPayload($mapped);
            
            Log::info('Robaws payload (api shape)', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'top' => array_keys($payload),
                'client_id_debug' => $payload['clientId'] ?? null,
                'contact_email_debug' => $payload['contactEmail'] ?? null,
                'client_reference_debug' => $payload['clientReference'] ?? null,
                'payload_size' => strlen(json_encode($payload)),
            ]);
            
            // Block export if payload is empty
            if (empty($payload)) {
                return [
                    'success' => false,
                    'status' => 422,
                    'error' => 'Mapped payload is empty - no data to export',
                ];
            }
            
            // Generate payload hash for idempotency
            $payloadHash = hash('sha256', json_encode($payload, \JSON_PARTIAL_OUTPUT_ON_ERROR|\JSON_UNESCAPED_UNICODE));
            
            Log::info('Robaws payload (api shape)', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'top' => array_keys($payload),
                'xf_keys' => isset($payload['extraFields']) ? array_keys($payload['extraFields']) : [],
                'payload_size' => strlen(json_encode($payload)),
                'payload_hash' => substr($payloadHash, 0, 16),
            ]);

            // Generate idempotency key
            $idempotencyKey = $options['idempotency_key'] ?? $this->generateIdempotencyKey($intake, $payloadHash);

            // Export to Robaws
            if ($intake->robaws_quotation_id && !($options['create_new'] ?? false)) {
                // Update existing quotation
                $result = $this->apiClient->updateQuotation(
                    $intake->robaws_quotation_id, 
                    $payload, 
                    $idempotencyKey
                );
                $action = 'updated';
            } else {
                // Create new quotation
                $result = $this->apiClient->createQuotation($payload, $idempotencyKey);
                $action = 'created';
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($result['success']) {
                // Verify the offer was stored correctly
                if ($id = $result['quotation_id'] ?? null) {
                    $verify = $this->apiClient->getOffer($id);
                    
                    $stored = [];
                    if (!empty($verify['data']['extraFields'])) {
                        // Pull key fields from extraFields
                        foreach (['POR','POL','POD','FDEST','CARGO','DIM_BEFORE_DELIVERY','METHOD','VESSEL','ETA','JSON'] as $code) {
                            if (isset($verify['data']['extraFields'][$code])) {
                                $node = $verify['data']['extraFields'][$code];
                                $stored[$code] = $node['stringValue'] ?? ($node['booleanValue'] ?? null);
                            }
                        }
                    }
                    
                    Log::info('Robaws offer verification', [
                        'export_id' => $exportId,
                        'intake_id' => $intake->id,
                        'offer_id' => $id,
                        'verification_success' => $verify['success'] ?? false,
                        'verify_top' => [
                            'clientId'       => data_get($verify, 'data.clientId'),
                            'clientPresent'  => !empty(data_get($verify, 'data.client')),
                            'clientReference'=> data_get($verify, 'data.clientReference'),
                            'contactEmail'   => data_get($verify, 'data.contactEmail'),
                        ],
                        'legacy_compat' => [
                            'clientId' => data_get($verify, 'data.clientId'),
                            'hasClientObj' => !empty(data_get($verify, 'data.client')),
                        ],
                        'top' => array_intersect_key($verify['data'] ?? [], array_flip([
                            'clientId','clientReference','title','contactEmail'
                        ])),
                        'xf' => $stored,
                    ]);
                }

                // Update intake with export info
                $updateData = [
                    'robaws_quotation_id' => $result['quotation_id'],
                    'exported_at' => now(),
                    'export_payload_hash' => hash('sha256', json_encode($payload)),
                    'export_attempt_count' => DB::raw('COALESCE(export_attempt_count, 0) + 1'),
                ];

                $intake->update($updateData);

                Log::info("Successfully {$action} Robaws quotation", [
                    'export_id' => $exportId,
                    'intake_id' => $intake->id,
                    'quotation_id' => $result['quotation_id'],
                    'action' => $action,
                    'duration_ms' => $duration,
                    'idempotency_key' => $result['idempotency_key'],
                ]);

                // Attach documents after successful offer creation/update
                $offerId = $result['quotation_id'];
                $this->attachDocumentsToOffer($intake, $offerId, $exportId);

                return [
                    'success' => true,
                    'action' => $action,
                    'quotation_id' => $result['quotation_id'],
                    'idempotency_key' => $result['idempotency_key'],
                    'duration_ms' => $duration,
                    'data' => $result['data'],
                ];
            }

            // Handle API failure
            $intake->increment('export_attempt_count');
            $intake->update(['last_export_error' => $result['error']]);

            Log::error('Robaws API failure', [
                'intake_id' => $intake->id,
                'status' => $result['status'] ?? null,
                'error' => $result['error'] ?? 'unknown',
                'request_id' => $result['request_id'] ?? null,
                'headers' => array_intersect_key(($result['headers'] ?? []), ['x-ratelimit-remaining'=>1,'retry-after'=>1]),
            ]);

            Log::error('Robaws export failed', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'error' => $result['error'],
                'status' => $result['status'] ?? null,
                'duration_ms' => $duration,
                'attempt_count' => $intake->export_attempt_count + 1,
            ]);

            return [
                'success' => false,
                'error' => $result['error'],
                'status' => $result['status'] ?? null,
                'duration_ms' => $duration,
                'details' => $result['data'] ?? [],
            ];

        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $intake->increment('export_attempt_count');
            $intake->update(['last_export_error' => $e->getMessage()]);

            Log::error('Unexpected error during Robaws export', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'duration_ms' => $duration,
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'Unexpected error: ' . $e->getMessage(),
                'duration_ms' => $duration,
            ];
        }
    }

    /**
     * Bulk export multiple intakes
     */
    public function bulkExport(array $intakeIds, array $options = []): array
    {
        $results = [];
        $successCount = 0;
        $failureCount = 0;
        $startTime = microtime(true);

        Log::info('Starting bulk Robaws export', [
            'intake_count' => count($intakeIds),
            'options' => $options,
        ]);

        foreach ($intakeIds as $intakeId) {
            $intake = Intake::find($intakeId);
            
            if (!$intake) {
                $results[$intakeId] = [
                    'success' => false,
                    'error' => 'Intake not found',
                ];
                $failureCount++;
                continue;
            }

            $result = $this->exportIntake($intake, $options);
            $results[$intakeId] = $result;

            if ($result['success']) {
                $successCount++;
            } else {
                $failureCount++;
            }

            // Add delay between requests to avoid rate limiting
            if (count($intakeIds) > 1) {
                usleep(($options['delay_ms'] ?? 100) * 1000);
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('Bulk export completed', [
            'total' => count($intakeIds),
            'success' => $successCount,
            'failure' => $failureCount,
            'duration_ms' => $duration,
        ]);

        return [
            'success' => $failureCount === 0,
            'total' => count($intakeIds),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'duration_ms' => $duration,
            'results' => $results,
        ];
    }

    /**
     * Test connection to Robaws API
     */
    public function testConnection(): array
    {
        Log::info('Testing Robaws API connection');
        
        $configCheck = $this->apiClient->validateConfig();
        if (!$configCheck['valid']) {
            return [
                'success' => false,
                'error' => 'Configuration invalid',
                'issues' => $configCheck['issues'],
            ];
        }

        $result = $this->apiClient->testConnection();
        
        Log::info('Robaws connection test result', [
            'success' => $result['success'],
            'status' => $result['status'] ?? null,
            'response_time' => $result['response_time'] ?? null,
        ]);

        return $result;
    }

    /**
     * Get audit information for export troubleshooting
     */
    public function getExportAudit(Intake $intake): array
    {
        $extractionData = $this->getExtractionData($intake);
        $payload = $this->mapper->mapIntakeToRobaws($intake, $extractionData);
        
        return [
            'intake' => [
                'id' => $intake->id,
                'customer_name' => $intake->customer_name,
                'robaws_quotation_id' => $intake->robaws_quotation_id,
                'exported_at' => $intake->exported_at,
                'export_attempt_count' => $intake->export_attempt_count ?? 0,
                'last_export_error' => $intake->last_export_error,
            ],
            'extraction_summary' => [
                'total_size' => strlen(json_encode($extractionData)),
                'sections' => array_keys($extractionData),
                'has_vehicle' => !empty($extractionData['vehicle']),
                'has_shipping' => !empty($extractionData['shipping']),
                'has_contact' => !empty($extractionData['contact']),
            ],
            'payload_summary' => [
                'total_size' => strlen(json_encode($payload)),
                'sections' => array_keys($payload),
                'has_json_field' => !empty($payload['automation']['json']),
                'json_size' => strlen($payload['automation']['json'] ?? ''),
            ],
            'mapping_completeness' => $this->analyzeMapping($payload),
        ];
    }

    // Private helper methods

    /**
     * Enhanced extraction value resolver with database fallback + AI fallback
     */
    private function extractionValue(Intake $intake, string $path, ?string $regexHint = null): ?string
    {
        // A) document-level structured data first
        foreach ($intake->documents as $doc) {
            $data = $doc->extraction->extracted_data ?? $doc->extraction->data ?? null;
            $val = data_get($data, $path);
            if ($val) return is_string($val) ? trim($val) : $val;
        }

        // B) intake-level structured data
        $val = data_get($intake->extraction->extracted_data ?? $intake->extraction->data ?? null, $path);
        if ($val) return is_string($val) ? trim($val) : $val;

        // C) heuristic (regex) on plain text as a last resort
        if ($regexHint) {
            foreach ($intake->documents as $doc) {
                $txt = $doc->plain_text ?? '';
                if ($txt && preg_match($regexHint, $txt, $m)) {
                    return trim($m[1] ?? $m[0]);
                }
            }
        }

        return null;
    }

    /**
     * Attach documents to Robaws offer
     */
    private function attachDocumentsToOffer(Intake $intake, int $offerId, string $exportId): void
    {
        // Pick "approved" docs (or sensible default if none approved yet)
        $docs = $intake->documents()->where(function ($q) {
            $q->where('status', 'approved')->orWhereNull('status');
        })->get();

        if ($docs->isEmpty()) {
            Log::info('No documents to attach', ['export_id' => $exportId, 'offer_id' => $offerId]);
            return;
        }

        foreach ($docs as $doc) {
            $path = $doc->filepath ?? $doc->path ?? null;
            if (!$path || !Storage::exists($path)) {
                Log::warning('Doc path missing or not found', [
                    'export_id' => $exportId,
                    'doc_id' => $doc->id,
                    'path' => $path
                ]);
                continue;
            }

            try {
                $absolutePath = Storage::path($path);
                $result = $this->apiClient->attachFileToOffer($offerId, $absolutePath);
                
                Log::info('Attached document to offer', [
                    'export_id' => $exportId,
                    'offer_id' => $offerId,
                    'doc_id' => $doc->id,
                    'path' => $path,
                    'result' => $result
                ]);
            } catch (\Throwable $e) {
                Log::error('Attach failed', [
                    'export_id' => $exportId,
                    'offer_id' => $offerId,
                    'doc_id' => $doc->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function getExtractionData(Intake $intake): array
    {
        // Normalize to array even if DB stores a JSON string
        $normalize = function ($raw) {
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                return is_array($decoded) ? $decoded : [];
            }
            return is_array($raw) ? $raw : [];
        };

        $base = $normalize($intake->extraction?->data ?? []);

        foreach ($intake->documents as $doc) {
            // Prefer singular extraction (latest) but fall back to last of extractions
            $docExtraction = $doc->extraction ?? $doc->extractions->last();
            $raw = $docExtraction?->data ?? $docExtraction?->extracted_data ?? null;
            $base = array_replace_recursive($base, $normalize($raw));
        }

        return $base;
    }

    private function wasRecentlyExported(Intake $intake): bool
    {
        if (!$intake->exported_at) {
            return false;
        }
        
        $threshold = Carbon::parse($intake->exported_at)->addMinutes(5);
        return now()->lt($threshold);
    }

    private function generateIdempotencyKey(Intake $intake, string $payloadHash): string
    {
        return sprintf('bconnect_%d_%s', $intake->id, substr($payloadHash, 0, 16));
    }

    private function analyzeMapping(array $payload): array
    {
        $analysis = [];
        
        // Check each section for completeness
        foreach ($payload as $section => $data) {
            $analysis[$section] = $this->analyzeMappingSection($data);
        }
        
        return $analysis;
    }

    private function analyzeMappingSection(array $data): array
    {
        $total = count($data);
        $filled = 0;
        $empty = 0;
        
        foreach ($data as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $filled++;
            } elseif (is_array($value) && !empty($value)) {
                $filled++;
            } elseif (!is_null($value) && $value !== '' && $value !== []) {
                $filled++;
            } else {
                $empty++;
            }
        }
        
        return [
            'total_fields' => $total,
            'filled_fields' => $filled,
            'empty_fields' => $empty,
            'completeness_percent' => $total > 0 ? round(($filled / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Backwards-compatible document upload used by tests and old callers.
     * - Checks local ledger by SHA-256 first (returns "exists")
     * - If not found, uses legacy client when available
     * - Otherwise falls back to the new API flow
     * Always returns a normalized shape expected by tests.
     */
    public function uploadDocumentToOffer(int $offerId, string $relativePath): array
    {
        // Resolve absolute file path and sanity-check
        $abs = $this->resolveAbsolutePath($relativePath);
        if (!$abs || !is_file($abs)) {
            return [
                'status'   => 'error',
                'error'    => 'File not found',
                'document' => ['id' => null, 'mime' => null, 'sha256' => null, 'size' => null],
            ];
        }

        // Compute hash & size (tests assert we preserve these on errors)
        [$sha256, $size, $mime] = $this->shaSizeMime($abs);

        // 1) Local ledger: return "exists" if we've seen this file before
        if ($ledger = RobawsDocument::where('sha256', $sha256)->first()) {
            return [
                'status'   => 'exists',
                'reason'   => 'local ledger',
                'document' => [
                    'id'     => (int) $ledger->robaws_document_id,
                    'mime'   => $ledger->mime_type ?? $mime,
                    'sha256' => $sha256,
                    'size'   => $size,
                ],
            ];
        }

        // 2) Try the legacy client if it exists (what the tests expect)
        Log::debug('BC method debug', [
            'has_legacy_client' => $this->legacy() !== null,
            'legacy_client_class' => $this->legacy() ? get_class($this->legacy()) : null,
            'app_bound_robaws_client' => app()->bound(RobawsClient::class),
        ]);
        
        if ($client = $this->legacy()) {
            $stream = fopen($abs, 'rb');
            try {
                $fileSpec = [
                    'filename' => basename($relativePath),
                    'mime'     => $mime ?: 'application/octet-stream',
                    'size'     => $size,
                    'stream'   => $stream,   // tests expect this
                ];

                $resp = $client->uploadDocument((string) $offerId, $fileSpec);

                // STRICTLY pull the *remote* id from the legacy response:
                $remoteId   = (int) (data_get($resp, 'document.id') ?? data_get($resp, 'id'));
                $remoteMime = data_get($resp, 'document.mime') ?? data_get($resp, 'mime') ?? $mime;

                Log::debug('Legacy upload debug', [
                    'remote_id' => $remoteId,
                    'resp' => $resp,
                    'data_get_document_id' => data_get($resp, 'document.id'),
                    'data_get_id' => data_get($resp, 'id'),
                ]);

                // Persist ledger entry but don't reuse the local model ID
                $ledgerEntry = RobawsDocument::create([
                    'robaws_offer_id'    => (string) $offerId,
                    'robaws_document_id' => $remoteId,     // <- remote id (77)
                    'sha256'             => $sha256,
                    'filename'           => basename($relativePath),
                    'filesize'           => $size,
                ]);

                Log::debug('Ledger entry created', [
                    'ledger_id' => $ledgerEntry->id,
                    'remote_id_stored' => $ledgerEntry->robaws_document_id,
                ]);

                return [
                    'status'   => 'uploaded',
                    'document' => [
                        'id'     => $remoteId,   // <- return remote id (77)
                        'mime'   => $remoteMime,
                        'sha256' => $sha256,
                        'size'   => $size,
                    ],
                    'meta'     => $resp,
                ];
            } catch (\Throwable $e) {
                return [
                    'status'   => 'error',
                    'error'    => $e->getMessage(),
                    'document' => [
                        'id'     => null,
                        'mime'   => null,
                        'sha256' => $sha256,
                        'size'   => $size,
                    ],
                ];
            } finally {
                if (is_resource($stream)) fclose($stream);
            }
        }

        // 3) Fallback: use the new API flow (temp bucket + patch offer)
        try {
            $this->apiClient->attachFileToOffer($offerId, $abs, basename($relativePath));

            // If your app keeps a local ledger, write it here too
            RobawsDocument::create([
                'robaws_offer_id'    => (string) $offerId,
                'robaws_document_id' => null, // set if the new flow returns an id
                'sha256'             => $sha256,
                'filename'           => basename($relativePath),
                'filesize'           => $size,
            ]);

            return [
                'status'   => 'uploaded',
                'document' => [
                    'id'     => null, // set when your client returns the created doc id
                    'mime'   => $mime,
                    'sha256' => $sha256,
                    'size'   => $size,
                ],
                'meta'     => ['flow' => 'new_api_temp_bucket'],
            ];
        } catch (\Throwable $e) {
            return [
                'status'   => 'error',
                'error'    => $e->getMessage(),
                'document' => [
                    'id'     => null,
                    'mime'   => null,
                    'sha256' => $sha256,
                    'size'   => $size,
                ],
            ];
        }
    }

    // === helpers ===========================================================

    private function resolveAbsolutePath(string $path, ?string $hintDisk = null): ?string
    {
        // 1) Already absolute on the filesystem?
        if (is_file($path)) {
            return $path;
        }

        // 2) disk://relative or disk:relative
        if (preg_match('/^([a-z0-9_]+):\/\/(.+)$/i', $path, $m) ||
            preg_match('/^([a-z0-9_]+):(.+)$/i', $path, $m)) {
            $disk = $m[1];
            $rel  = ltrim($m[2], '/');
            if (config("filesystems.disks.$disk") && Storage::disk($disk)->exists($rel)) {
                return Storage::disk($disk)->path($rel);
            }
        }

        $disksTried = [];

        // 3) If the first path segment equals a configured disk, use it
        if (str_contains($path, '/')) {
            $first = strtok($path, '/');
            if ($first && config("filesystems.disks.$first")) {
                $rel = substr($path, strlen($first) + 1);
                if (Storage::disk($first)->exists($rel)) {
                    return Storage::disk($first)->path($rel);
                }
                $disksTried[] = $first;
            }
        }

        // 4) Try hint disk, then default disk, then common disks
        $candidates = [];
        if ($hintDisk && config("filesystems.disks.$hintDisk")) $candidates[] = $hintDisk;

        $default = config('filesystems.default', 'local');
        if (!in_array($default, $disksTried, true)) $candidates[] = $default;

        foreach (['documents','local','public'] as $d) {
            if (!in_array($d, $candidates, true) && config("filesystems.disks.$d")) $candidates[] = $d;
        }

        foreach ($candidates as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->path($path);
            }
        }

        // 5) Last resort: storage_path
        $fallback = storage_path('app/' . ltrim($path, '/'));
        return is_file($fallback) ? $fallback : null;
    }

    private function shaSizeMime(string $absPath): array
    {
        $body = file_get_contents($absPath);
        $sha  = hash('sha256', $body);
        $size = strlen($body);

        $mime = function_exists('mime_content_type') ? mime_content_type($absPath) : null;
        if (!$mime || $mime === 'text/plain') {
            $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'eml'        => 'message/rfc822',
                'pdf'        => 'application/pdf',
                'jpg','jpeg' => 'image/jpeg',
                'png'        => 'image/png',
                default      => $mime ?: 'application/octet-stream',
            };
        }

        return [$sha, $size, $mime];
    }

    private function attachViaNewFlow(int $offerId, string $absPath, string $filename): void
    {
        // Reuse your new API client's "temp bucket + patch offer" flow.
        // Assumes you already implemented something like attachFileToOffer().
        $this->apiClient->attachFileToOffer($offerId, $absPath, $filename);
    }

    /**
     * Detect MIME type of file
     */
    private function detectMime(string $file): ?string
    {
        if (!is_readable($file)) {
            return null;
        }
        
        $fi = new \finfo(FILEINFO_MIME_TYPE);
        return $fi->file($file) ?: null;
    }
}

<?php

namespace App\Services\Robaws;

use App\Models\Intake;
use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class RobawsExportService
{
    private RobawsMapper $mapper;
    private RobawsApiClient $apiClient;

    public function __construct(RobawsMapper $mapper, RobawsApiClient $apiClient)
    {
        $this->mapper = $mapper;
        $this->apiClient = $apiClient;
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

            // Map to Robaws format
            $mapped = $this->mapper->mapIntakeToRobaws($intake, $extractionData);
            $payload = $this->mapper->toRobawsApiPayload($mapped);   // <-- NEW API FORMAT
            
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
                        'top' => array_intersect_key($verify['data'] ?? [], array_flip(['title','project','clientReference','contactEmail'])),
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
}

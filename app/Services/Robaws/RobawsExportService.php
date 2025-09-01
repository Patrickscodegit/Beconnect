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
            $payload = $this->mapper->mapIntakeToRobaws($intake, $extractionData);
            
            Log::info('Mapped payload structure', [
                'export_id' => $exportId,
                'intake_id' => $intake->id,
                'sections' => array_keys($payload),
                'payload_size' => strlen(json_encode($payload)),
                'has_json_field' => !empty($payload['automation']['json']),
            ]);

            // Generate idempotency key
            $idempotencyKey = $options['idempotency_key'] ?? $this->generateIdempotencyKey($intake, $extractionData);

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
        $base = $intake->extraction?->data ?? [];

        foreach ($intake->documents as $doc) {
            $docData = $doc->extraction?->data ?? [];
            $base = array_replace_recursive($base, $docData);
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

    private function generateIdempotencyKey(Intake $intake, array $extractionData): string
    {
        $data = [
            'intake_id' => $intake->id,
            'customer' => $intake->customer_name,
            'vehicle' => $extractionData['vehicle']['vin'] ?? $extractionData['vehicle']['brand'] ?? '',
            'timestamp' => $intake->updated_at->timestamp,
        ];
        
        $hash = hash('sha256', json_encode($data, JSON_SORT_KEYS));
        return 'bconnect_' . $intake->id . '_' . substr($hash, 0, 16);
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

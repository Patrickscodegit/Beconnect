<?php

namespace App\Services\Extraction\Results;

class ExtractionResult
{
    public function __construct(
        private bool $success,
        private array $data,
        private float $confidence,
        private string $strategyUsed,
        private array $metadata = []
    ) {}

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function getStrategyUsed(): string
    {
        return $this->strategyUsed;
    }

    public function getStrategy(): string
    {
        return $this->strategyUsed;
    }

    public function getMetadata(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->metadata;
        }
        return data_get($this->metadata, $key, $default);
    }

    /**
     * Get only document-sourced data (not AI-enhanced)
     */
    public function getDocumentData(): array
    {
        return $this->filterDocumentData($this->data);
    }

        /**
     * Get AI-enhanced data separately
     */
    public function getAiEnhancedData(): array
    {
        $enhanced = [];
        
        // Extract database-enhanced fields
        if (isset($this->data['vehicle']['database_match']) && $this->data['vehicle']['database_match']) {
            $enhanced['vehicle_database'] = [
                'database_id' => $this->data['vehicle']['database_id'] ?? null,
                'source' => 'vehicle_specs_database',
                'confidence' => $this->data['vehicle']['database_confidence'] ?? 0.95,
                'enhanced_fields' => []
            ];
            
            // Identify which fields were database-enhanced
            $documentVehicle = $this->getDocumentData()['vehicle'] ?? [];
            $fullVehicle = $this->data['vehicle'] ?? [];
            
            foreach ($fullVehicle as $key => $value) {
                if (!isset($documentVehicle[$key]) && 
                    !in_array($key, ['database_match', 'database_id', 'database_confidence', 'validation', 'vin_decoded'])) {
                    $enhanced['vehicle_database']['enhanced_fields'][$key] = $value;
                }
            }
        }
        
        // Extract VIN-decoded data
        if (isset($this->data['vehicle']['vin_decoded'])) {
            $enhanced['vin_decode'] = [
                'source' => 'vin_wmi_database',
                'data' => $this->data['vehicle']['vin_decoded'],
                'confidence' => 0.98
            ];
        }
        
        return $enhanced;
    }

    /**
     * Get all metadata
     */
    public function getAllMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get error message if extraction failed
     */
    public function getErrorMessage(): string
    {
        return $this->data['error'] ?? 'Unknown error occurred';
    }

    /**
     * Add metadata to the result
     */
    public function addMetadata(string $key, $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Get data attribution details
     */
    public function getDataAttribution(): array
    {
        return [
            'document_fields' => array_keys($this->getDocumentData()),
            'ai_enhanced_fields' => array_keys($this->getAiEnhancedData()),
            'strategy_used' => $this->strategyUsed,
            'overall_confidence' => $this->confidence
        ];
    }

    /**
     * Convert to array for storage
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'document_data' => $this->getDocumentData(),
            'ai_enhanced_data' => $this->getAiEnhancedData(),
            'data_attribution' => $this->getDataAttribution(),
            'confidence' => $this->confidence,
            'strategy_used' => $this->strategyUsed,
            'metadata' => $this->metadata,
            'extracted_at' => now()->toIso8601String()
        ];
    }

    /**
     * Filter out AI-enhanced fields to get only document data
     */
    private function filterDocumentData(array $data): array
    {
        $filtered = [];
        
        foreach ($data as $key => $value) {
            // Skip metadata and validation fields
            if (str_starts_with($key, '_') || 
                in_array($key, ['database_validation', 'final_validation'])) {
                continue;
            }
            
            if (is_array($value)) {
                $filteredValue = $this->filterSectionData($value, $key);
                if (!empty($filteredValue)) {
                    $filtered[$key] = $filteredValue;
                }
            } else {
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
    }

    /**
     * Filter section data to remove AI-enhanced fields
     */
    private function filterSectionData(array $sectionData, string $sectionName): array
    {
        $filtered = [];
        
        foreach ($sectionData as $key => $value) {
            // Skip database-enhanced fields for vehicle section
            if ($sectionName === 'vehicle') {
                $skipFields = [
                    'database_match', 'database_id', 'database_confidence', 'database_source',
                    'validation', 'vin_decoded', 'wmi_data', 'manufacturer_match'
                ];
                
                if (in_array($key, $skipFields)) {
                    continue;
                }
                
                // Skip fields that are typically database-enhanced
                if (empty($this->getOriginalVehicleFields()) || 
                    !in_array($key, $this->getOriginalVehicleFields())) {
                    if (in_array($key, ['variant', 'length_m', 'width_m', 'height_m', 'wheelbase_m', 'transmission'])) {
                        continue;
                    }
                }
            }
            
            if (is_array($value)) {
                $filtered[$key] = $this->filterSectionData($value, $key);
            } else {
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
    }

    /**
     * Get fields that are typically extracted from documents
     */
    private function getOriginalVehicleFields(): array
    {
        return [
            'brand', 'make', 'model', 'year', 'color', 'condition', 
            'vin', 'engine_cc', 'fuel_type', 'weight_kg', 'mileage_km', 'dimensions'
        ];
    }

    /**
     * Flatten nested array to get field names
     */
    private function flattenFieldNames(array $data, string $prefix = ''): array
    {
        $fields = [];
        
        foreach ($data as $key => $value) {
            $fieldName = $prefix ? "{$prefix}.{$key}" : $key;
            
            if (is_array($value) && !empty($value)) {
                $fields = array_merge($fields, $this->flattenFieldNames($value, $fieldName));
            } else {
                $fields[] = $fieldName;
            }
        }
        
        return $fields;
    }

    /**
     * Create a successful result
     */
    public static function success(array $data, float $confidence, string $strategy, array $metadata = []): self
    {
        return new self(true, $data, $confidence, $strategy, $metadata);
    }

    /**
     * Create a failed result
     */
    public static function failure(string $strategy, string $error, array $metadata = []): self
    {
        return new self(false, ['error' => $error], 0.0, $strategy, $metadata);
    }
}

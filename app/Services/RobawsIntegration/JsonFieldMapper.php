<?php

namespace App\Services\RobawsIntegration;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class JsonFieldMapper
{
    private array $mappingConfig;
    private array $transformations;
    private array $validationRules;
    
    public function __construct()
    {
        $this->loadMappingConfiguration();
    }
    
    /**
     * Load the JSON mapping configuration
     */
    private function loadMappingConfiguration(): void
    {
        $configPath = config_path('robaws-field-mapping.json');
        
        if (!file_exists($configPath)) {
            throw new \RuntimeException('Robaws field mapping configuration not found');
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON in Robaws field mapping: ' . json_last_error_msg());
        }
        
        $this->mappingConfig = $config['field_mappings'] ?? [];
        $this->transformations = $config['transformations'] ?? [];
        $this->validationRules = $config['validation_rules'] ?? [];
    }
    
    /**
     * Map extracted data to Robaws format using JSON configuration
     */
    public function mapFields(array $extractedData): array
    {
        $result = [];
        
        // Log input data for debugging
        Log::info('JsonFieldMapper: Starting field mapping', [
            'input_field_count' => count($extractedData),
            'has_json_field' => isset($extractedData['JSON']),
            'sample_fields' => array_slice(array_keys($extractedData), 0, 10)
        ]);
        
        foreach ($this->mappingConfig as $section => $fields) {
            foreach ($fields as $targetField => $config) {
                $value = $this->extractFieldValue($extractedData, $config);
                if ($value !== null) {
                    $result[$targetField] = $value;
                }
            }
        }
        
        // Handle raw JSON data fields specifically for Robaws
        $result = $this->mapRawJsonFields($extractedData, $result);
        
        // Add computed fields
        $result['formatted_at'] = now()->toISOString();
        $result['source'] = 'bconnect_ai_extraction';
        $result['mapping_version'] = $this->getConfigVersion();
        
        // Log mapping results
        Log::info('JsonFieldMapper: Field mapping completed', [
            'mapped_field_count' => count($result),
            'has_json' => isset($result['JSON']),
            'has_raw_json' => isset($result['raw_json']),
            'sample_mapped_fields' => array_slice(array_keys($result), 0, 10)
        ]);
        
        return $result;
    }
    
    /**
     * Map raw JSON fields for Robaws JSON tab display
     */
    private function mapRawJsonFields(array $extractedData, array $result): array
    {
        // Ensure the main JSON field exists for Robaws JSON tab
        if (isset($extractedData['JSON'])) {
            $result['JSON'] = $extractedData['JSON'];
            Log::info('JsonFieldMapper: Mapped JSON field', [
                'json_length' => strlen($extractedData['JSON'])
            ]);
        } elseif (isset($extractedData['raw_json'])) {
            $result['JSON'] = $extractedData['raw_json'];
            $result['raw_json'] = $extractedData['raw_json'];
        } elseif (isset($extractedData['extraction_json'])) {
            $result['JSON'] = $extractedData['extraction_json'];
            $result['extraction_json'] = $extractedData['extraction_json'];
        }
        
        // Also preserve alternative JSON field names
        foreach (['raw_json', 'extraction_json', 'complete_data'] as $jsonField) {
            if (isset($extractedData[$jsonField])) {
                $result[$jsonField] = $extractedData[$jsonField];
            }
        }
        
        // If no JSON field exists, create one from the complete extraction data
        if (!isset($result['JSON'])) {
            $result['JSON'] = json_encode([
                'extraction_data' => $extractedData,
                'created_at' => now()->toIso8601String(),
                'source' => 'bconnect_fallback_json'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            Log::info('JsonFieldMapper: Created fallback JSON field', [
                'json_length' => strlen($result['JSON'])
            ]);
        }
        
        return $result;
    }
    
    /**
     * Extract field value based on configuration
     */
    private function extractFieldValue(array $data, array $config): mixed
    {
        // Handle template-based fields
        if (isset($config['template'])) {
            return $this->processTemplate($data, $config);
        }
        
        // Handle direct source mapping
        if (isset($config['sources'])) {
            $value = $this->findValueFromSources($data, $config['sources']);
            
            // Apply fallback if configured
            if ($value === null && isset($config['fallback'])) {
                $value = $this->applyFallback($data, $config['fallback']);
            }
            
            // Apply transformation if configured
            if ($value !== null && isset($config['transform'])) {
                $value = $this->applyTransformation($value, $config['transform'], $data);
            }
            
            // Apply validation if configured
            if ($value !== null && isset($config['validate'])) {
                $value = $this->validateValue($value, $config['validate']) ? $value : null;
            }
            
            // Return default if still null
            return $value ?? ($config['default'] ?? null);
        }
        
        return $config['default'] ?? null;
    }
    
    /**
     * Process template-based fields
     */
    private function processTemplate(array $data, array $config): string
    {
        $template = $config['template'];
        $components = [];
        
        if (isset($config['components'])) {
            foreach ($config['components'] as $key => $componentConfig) {
                $components[$key] = $this->extractFieldValue($data, $componentConfig);
            }
        } elseif (isset($config['sources'])) {
            foreach ($config['sources'] as $key => $sources) {
                $components[$key] = $this->findValueFromSources($data, $sources);
            }
        }
        
        // Replace template variables
        foreach ($components as $key => $value) {
            $template = str_replace('{' . $key . '}', $value ?? '', $template);
        }
        
        // Clean up template
        $template = $this->cleanTemplate($template);
        
        return $template ?: ($config['default'] ?? '');
    }
    
    /**
     * Clean up template by removing empty components
     */
    private function cleanTemplate(string $template): string
    {
        // Remove empty components like "x  x" or "- -"
        $template = preg_replace('/\s+x\s+x/', ' x', $template);
        $template = preg_replace('/-\s+-/', '-', $template);
        $template = preg_replace('/\s{2,}/', ' ', $template);
        $template = trim($template);
        
        // Remove trailing separators
        $template = rtrim($template, ' -x');
        
        return $template;
    }
    
    /**
     * Find value from multiple source paths
     */
    private function findValueFromSources(array $data, array|string $sources): mixed
    {
        if (is_string($sources)) {
            $sources = [$sources];
        }
        
        foreach ($sources as $source) {
            $value = data_get($data, $source);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        
        return null;
    }
    
    /**
     * Apply transformation to value
     */
    private function applyTransformation(mixed $value, string $transform, array $fullData = []): mixed
    {
        switch ($transform) {
            case 'extract_name':
                return $this->extractNameFromEmail($value);
                
            case 'city_to_code':
                return $this->transformations['city_to_code'][$value] ?? $this->extractCodeFromCity($value);
                
            case 'city_to_port':
                foreach ($this->transformations['city_to_port'] as $city => $port) {
                    if (stripos($value, $city) !== false) {
                        return $port;
                    }
                }
                return $value;
                
            case 'calculate_volume':
                return $this->calculateVolumeFromDimensions($fullData);
                
            case 'to_numeric':
                return is_numeric($value) ? floatval($value) : null;
                
            case 'to_integer':
                return is_numeric($value) ? intval($value) : null;
                
            case 'to_date':
                try {
                    return $value ? \Carbon\Carbon::parse($value)->format('Y-m-d') : null;
                } catch (\Exception $e) {
                    return null;
                }
                
            case 'format_messages':
                return $this->formatMessages($value);
                
            case 'clean_vehicle_model':
                return $this->cleanVehicleModel($value);
                
            case 'extract_engine_cc':
                return $this->extractEngineCC($value);
                
            case 'standardize_fuel_type':
                return $this->standardizeFuelType($value);
                
            case 'extract_weight_numeric':
                return $this->extractWeightNumeric($value);
                
            case 'to_meters':
                return $this->convertToMeters($value);
                
            default:
                return $value;
        }
    }
    
    /**
     * Apply fallback strategy
     */
    private function applyFallback(array $data, string $fallback): mixed
    {
        switch ($fallback) {
            case 'extract_from_messages':
                return $this->extractFromMessages($data);
                
            case 'calculate_from_vehicle_dims':
                return $this->calculateDimensionsFromVehicle($data);
                
            default:
                return null;
        }
    }
    
    /**
     * Validate value based on rule
     */
    private function validateValue(mixed $value, string $rule): bool
    {
        if (!isset($this->validationRules[$rule])) {
            return true;
        }
        
        $ruleConfig = $this->validationRules[$rule];
        
        switch ($ruleConfig['type']) {
            case 'regex':
                return preg_match('/' . $ruleConfig['pattern'] . '/', $value);
                
            case 'numeric':
                if (!is_numeric($value)) return false;
                if (isset($ruleConfig['min']) && $value < $ruleConfig['min']) return false;
                if (isset($ruleConfig['max']) && $value > $ruleConfig['max']) return false;
                return true;
                
            case 'date':
                try {
                    \Carbon\Carbon::parse($value);
                    return true;
                } catch (\Exception $e) {
                    return false;
                }
                
            default:
                return true;
        }
    }
    
    /**
     * Extract name from email format
     */
    private function extractNameFromEmail(string $email): string
    {
        if (preg_match('/^(.+?)\s*<(.+?)>$/', $email, $matches)) {
            return trim($matches[1]);
        }
        
        // Try to extract from email address before @
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $parts = explode('@', $email);
            return ucwords(str_replace('.', ' ', $parts[0]));
        }
        
        return $email;
    }
    
    /**
     * Extract code from city name
     */
    private function extractCodeFromCity(string $city): string
    {
        // Try to extract from existing mappings using partial matches
        foreach ($this->transformations['city_to_code'] as $fullName => $code) {
            if (stripos($fullName, $city) !== false || stripos($city, explode(',', $fullName)[0]) !== false) {
                return $code;
            }
        }
        
        // Generate a simple code from city name
        $cleanCity = preg_replace('/[^a-zA-Z]/', '', $city);
        return strtoupper(substr($cleanCity, 0, 3));
    }
    
    /**
     * Calculate volume and format dimensions
     */
    private function calculateVolumeFromDimensions(array $data): ?string
    {
        $length = data_get($data, 'vehicle.dimensions.length_m') ?? 
                  data_get($data, 'dimensions.length');
        $width = data_get($data, 'vehicle.dimensions.width_m') ?? 
                 data_get($data, 'dimensions.width');
        $height = data_get($data, 'vehicle.dimensions.height_m') ?? 
                  data_get($data, 'dimensions.height');
        
        if (!$length || !$width || !$height) {
            return null;
        }
        
        $volume = floatval($length) * floatval($width) * floatval($height);
        
        return sprintf('%.3f x %.2f x %.3f m // %.2f Cbm', 
            floatval($length), 
            floatval($width), 
            floatval($height), 
            $volume
        );
    }
    
    /**
     * Format messages for internal remarks
     */
    private function formatMessages($messages): ?string
    {
        if (!is_array($messages) || empty($messages)) {
            return null;
        }
        
        $formatted = [];
        foreach ($messages as $message) {
            if (isset($message['text'])) {
                $sender = $message['sender'] ?? 'User';
                $timestamp = $message['timestamp'] ?? $message['time'] ?? '';
                $timePrefix = $timestamp ? '[' . date('H:i', strtotime($timestamp)) . '] ' : '';
                $formatted[] = $timePrefix . $sender . ': ' . $message['text'];
            }
        }
        
        return !empty($formatted) ? implode("\n", $formatted) : null;
    }
    
    /**
     * Extract location from messages
     */
    private function extractFromMessages(array $data): ?string
    {
        if (!isset($data['messages'])) {
            return null;
        }
        
        foreach ($data['messages'] as $message) {
            if (isset($message['text'])) {
                // Look for origin/destination patterns
                if (preg_match('/from\s+([^to]+?)\s+to\s+([^,\.\n]+)/i', $message['text'], $matches)) {
                    return trim($matches[1]); // Return origin for now
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get configuration version
     */
    private function getConfigVersion(): string
    {
        $config = json_decode(file_get_contents(config_path('robaws-field-mapping.json')), true);
        return $config['version'] ?? '1.0';
    }
    
    /**
     * Reload configuration (useful for testing or runtime updates)
     */
    public function reloadConfiguration(): void
    {
        $this->loadMappingConfiguration();
    }
    
    /**
     * Get field mapping summary for debugging
     */
    public function getMappingSummary(): array
    {
        $summary = [
            'version' => $this->getConfigVersion(),
            'sections' => array_keys($this->mappingConfig),
            'total_fields' => 0,
            'fields_by_section' => []
        ];
        
        foreach ($this->mappingConfig as $section => $fields) {
            $summary['fields_by_section'][$section] = count($fields);
            $summary['total_fields'] += count($fields);
        }
        
        return $summary;
    }
    
    /**
     * Clean vehicle model name
     */
    private function cleanVehicleModel(string $model): string
    {
        // Remove brand name if it's at the beginning
        $commonBrands = ['BMW', 'Mercedes', 'Audi', 'Volkswagen', 'Toyota', 'Honda', 'Ford'];
        foreach ($commonBrands as $brand) {
            if (stripos($model, $brand) === 0) {
                $model = trim(substr($model, strlen($brand)));
                break;
            }
        }
        
        return trim($model);
    }
    
    /**
     * Extract engine CC from various formats
     */
    private function extractEngineCC($value): ?int
    {
        if (!$value) return null;
        
        // Extract numbers followed by CC, L, or other engine indicators
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:cc|l|liters?|litres?)/i', $value, $matches)) {
            $size = floatval($matches[1]);
            
            // Convert liters to CC if needed
            if (stripos($value, 'l') !== false && $size < 10) {
                return intval($size * 1000);
            }
            
            return intval($size);
        }
        
        // If it's just a number, assume it's CC
        if (is_numeric($value)) {
            return intval($value);
        }
        
        return null;
    }
    
    /**
     * Standardize fuel type
     */
    private function standardizeFuelType($value): ?string
    {
        if (!$value) return null;
        
        $value = strtolower(trim($value));
        
        $fuelMapping = [
            'petrol' => 'Petrol',
            'gasoline' => 'Petrol', 
            'gas' => 'Petrol',
            'diesel' => 'Diesel',
            'electric' => 'Electric',
            'hybrid' => 'Hybrid',
            'lpg' => 'LPG',
            'cng' => 'CNG'
        ];
        
        foreach ($fuelMapping as $pattern => $standard) {
            if (stripos($value, $pattern) !== false) {
                return $standard;
            }
        }
        
        return ucfirst($value);
    }
    
    /**
     * Extract numeric weight value
     */
    private function extractWeightNumeric($value): ?float
    {
        if (!$value) return null;
        
        // Extract number from weight strings like "1,950 kg" or "1950kg"
        if (preg_match('/([0-9,\.]+)\s*(?:kg|kilograms?|lbs?|pounds?)?/i', $value, $matches)) {
            $weight = floatval(str_replace(',', '', $matches[1]));
            
            // Convert pounds to kg if detected
            if (stripos($value, 'lb') !== false || stripos($value, 'pound') !== false) {
                $weight = $weight * 0.453592;
            }
            
            return $weight;
        }
        
        if (is_numeric($value)) {
            return floatval($value);
        }
        
        return null;
    }
    
    /**
     * Convert various measurements to meters
     */
    private function convertToMeters($value): ?float
    {
        if (!$value) return null;
        
        // Extract number and unit
        if (preg_match('/([0-9,\.]+)\s*(?:m|meters?|cm|centimeters?|mm|millimeters?|ft|feet|in|inches?)?/i', $value, $matches)) {
            $measurement = floatval(str_replace(',', '', $matches[1]));
            $unit = strtolower(trim(substr($value, strlen($matches[1]))));
            
            switch ($unit) {
                case 'cm':
                case 'centimeters':
                case 'centimeter':
                    return $measurement / 100;
                    
                case 'mm':
                case 'millimeters':
                case 'millimeter':
                    return $measurement / 1000;
                    
                case 'ft':
                case 'feet':
                    return $measurement * 0.3048;
                    
                case 'in':
                case 'inches':
                case 'inch':
                    return $measurement * 0.0254;
                    
                case 'm':
                case 'meters':
                case 'meter':
                case '':
                default:
                    return $measurement;
            }
        }
        
        if (is_numeric($value)) {
            return floatval($value);
        }
        
        return null;
    }
    
    /**
     * Calculate formatted dimensions from vehicle data
     */
    private function calculateDimensionsFromVehicle(array $data): ?string
    {
        // Try to get individual dimensions
        $length = data_get($data, 'vehicle.length') ?? 
                  data_get($data, 'vehicle.dimensions.length') ?? 
                  data_get($data, 'vehicle_details.length');
                  
        $width = data_get($data, 'vehicle.width') ?? 
                 data_get($data, 'vehicle.dimensions.width') ?? 
                 data_get($data, 'vehicle_details.width');
                 
        $height = data_get($data, 'vehicle.height') ?? 
                  data_get($data, 'vehicle.dimensions.height') ?? 
                  data_get($data, 'vehicle_details.height');
        
        if ($length && $width && $height) {
            $l = $this->convertToMeters($length);
            $w = $this->convertToMeters($width);
            $h = $this->convertToMeters($height);
            
            if ($l && $w && $h) {
                $volume = $l * $w * $h;
                return sprintf('%.2f x %.2f x %.2f m // %.2f Cbm', $l, $w, $h, $volume);
            }
        }
        
        // Try to find already formatted dimensions
        $formatted = data_get($data, 'vehicle.dimensions_formatted') ?? 
                    data_get($data, 'cargo.dimensions') ?? 
                    data_get($data, 'shipment.dimensions');
                    
        if ($formatted) {
            return $formatted;
        }
        
        return null;
    }
}

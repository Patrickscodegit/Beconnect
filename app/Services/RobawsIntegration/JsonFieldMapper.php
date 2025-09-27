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
        
        // PHASE 1 STEP 3: Enhanced logging for debugging
        Log::channel('robaws')->info('JsonFieldMapper: Starting field mapping', [
            'input_field_count' => count($extractedData),
            'has_json_field' => isset($extractedData['JSON']),
            'has_vehicle_make' => isset($extractedData['vehicle_make']),
            'has_origin' => isset($extractedData['origin']),
            'sample_fields' => array_slice(array_keys($extractedData), 0, 20),
            'json_preview' => isset($extractedData['JSON']) ? substr($extractedData['JSON'], 0, 200) . '...' : 'NO JSON FIELD'
        ]);
        
        foreach ($this->mappingConfig as $section => $fields) {
            foreach ($fields as $targetField => $config) {
                $value = $this->extractFieldValue($extractedData, $config);
                
                // POL fallback: if POL is empty but we have POR, try to map POR to port
                if ($targetField === 'pol' && empty($value)) {
                    $por = $result['por'] ?? $this->findValueFromSources($extractedData, [
                        'shipment.origin',
                        'routing.origin',
                        'document_data.shipment.origin',
                        'document_data.routing.origin',
                        'document_data.routing.por',
                        'origin',
                    ]);
                    if ($por) {
                        $value = $this->applyTransformation($por, 'city_to_port', $extractedData);
                    }
                }
                
                if ($value !== null) {
                    $result[$targetField] = $value;
                }
            }
        }
        
        // Handle raw JSON data fields specifically for Robaws
        $result = $this->mapRawJsonFields($extractedData, $result);
        
        // Add computed fields
        $result['formatted_at'] = now()->toIso8601String();
        $result['source'] = 'bconnect_ai_extraction';
        $result['mapping_version'] = $this->getConfigVersion();
        
        // Log mapping results
        Log::channel('robaws')->info('JsonFieldMapper: Field mapping completed', [
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
            Log::channel('robaws')->info('JsonFieldMapper: Mapped JSON field', [
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
            
            Log::channel('robaws')->info('JsonFieldMapper: Created fallback JSON field', [
                'json_length' => strlen($result['JSON'])
            ]);
        }
        
        return $result;
    }
    
    /**
     * Extract field value based on configuration - sources first, then templates
     */
    private function extractFieldValue(array $data, array $config): mixed
    {
        // 1) Try sources first
        if (isset($config['sources'])) {
            $value = $this->findValueFromSources($data, $config['sources']);

            if (!$this->isBlank($value) && isset($config['transform'])) {
                $value = $this->applyTransformation($value, $config['transform'], $data);
            }

            // If we got something non-blank, validate (if any) and return
            if (!$this->isBlank($value)) {
                if (isset($config['validate']) && !$this->validateValue($value, $config['validate'])) {
                    $value = null;
                }
                if (!$this->isBlank($value)) {
                    return $value;
                }
            }

            // 2) Sources failed → use fallback template (or template)
            if (isset($config['fallback_template']) || isset($config['template'])) {
                $tplCfg = $config;
                if (isset($config['fallback_template'])) {
                    $tplCfg['template'] = $config['fallback_template'];
                }
                $tplValue = $this->processTemplate($data, $tplCfg);
                return !$this->isBlank($tplValue) ? $tplValue : ($config['default'] ?? null);
            }

            return $config['default'] ?? null;
        }

        // 3) No sources → template-only fields
        if (isset($config['template'])) {
            $v = $this->processTemplate($data, $config);
            return !$this->isBlank($v) ? $v : ($config['default'] ?? null);
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
                // Composite component (object with nested keys)
                if (isset($componentConfig['sources']) && is_array($componentConfig['sources']) && array_is_list($componentConfig['sources']) === false) {
                    $composite = [];
                    foreach ($componentConfig['sources'] as $ck => $csrc) {
                        if (is_array($csrc) && isset($csrc['sources'])) {
                            $val = $this->findValueFromSources($data, $csrc['sources']);
                            $composite[$ck] = $val ?? ($csrc['default'] ?? null);
                        } else {
                            $composite[$ck] = $this->findValueFromSources($data, $csrc);
                        }
                    }
                    $val = $composite;

                    // Apply transform/validate on composite
                    if (isset($componentConfig['transform'])) {
                        $val = $this->applyTransformation($val, $componentConfig['transform'], $data);
                    }
                    if (isset($componentConfig['validate']) && !$this->validateValue($val, $componentConfig['validate'])) {
                        $val = $componentConfig['default'] ?? null;
                    }

                    $components[$key] = $val;
                    continue;
                }

                // Simple component
                $val = $this->extractFieldValue($data, $componentConfig);
                // Only apply here if the component did NOT use sources (extractFieldValue already applied it for sources)
                if (isset($componentConfig['transform']) && !isset($componentConfig['sources'])) {
                    $val = $this->applyTransformation($val, $componentConfig['transform'], $data);
                }
                if (isset($componentConfig['validate']) && !$this->validateValue($val, $componentConfig['validate'])) {
                    $val = $componentConfig['default'] ?? null;
                }
                $components[$key] = $val;
            }
        } elseif (isset($config['sources'])) {
            // legacy branch — fine to leave as you had
            foreach ($config['sources'] as $key => $sources) {
                if (is_array($sources) && isset($sources['sources'])) {
                    $value = $this->findValueFromSources($data, $sources['sources']);
                    $components[$key] = $value ?: ($sources['default'] ?? null);
                } else {
                    $components[$key] = $this->findValueFromSources($data, $sources);
                }
            }
        }
        
        // Replace template variables
        foreach ($components as $key => $value) {
            // Handle array values properly
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $template = str_replace('{' . $key . '}', (string)($value ?? ''), $template);
        }
        
        // Clean up template
        $template = preg_replace('/\(\s*\)/', '', $template);
        $template = preg_replace('/\s{2,}/', ' ', trim($template));
        $template = rtrim($template, ' -x');
        
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
        
        // Remove empty parentheses
        $template = preg_replace('/\(\s*\)/', '', $template);
        
        $template = trim($template);
        
        // Remove trailing separators
        $template = rtrim($template, ' -x');
        
        return $template;
    }
    
    /**
     * Check if a value is considered blank/empty
     */
    private function isBlank(mixed $v): bool
    {
        if ($v === null) return true;
        if (is_string($v)) return trim($v) === '' || strtoupper(trim($v)) === 'NULL';
        if (is_array($v)) return count($v) === 0;
        return false;
    }
    
    /**
     * Find value from multiple source paths
     */
    private function findValueFromSources(array $data, array|string $sources): mixed
    {
        // Normalize to a uniform iterable of "items", where each item can be:
        // - string path
        // - array of paths
        // - associative ['sources'=>[...], 'default'=>...] block
        $items = is_array($sources) ? $sources : [$sources];

        foreach ($items as $item) {
            // Case A: associative block with "sources" and optional "default"
            if (is_array($item) && array_is_list($item) === false && isset($item['sources'])) {
                $candidate = $this->findValueFromSources($data, $item['sources']);
                if ($candidate !== null && $candidate !== '') {
                    return $candidate;
                }
                if (array_key_exists('default', $item)) {
                    // only return default if nothing found *in this block*
                    return $item['default'];
                }
                // else continue to next item
                continue;
            }

            // Case B: an array of paths (list)
            if (is_array($item) && array_is_list($item)) {
                foreach ($item as $path) {
                    $v = data_get($data, $path);
                    if ($v !== null && $v !== '') return $v;
                }
                continue;
            }

            // Case C: a single string path
            if (is_string($item)) {
                $v = data_get($data, $item);
                if ($v !== null && $v !== '') return $v;
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
                if (!is_string($value) || $value === '') return $value;
                $map = $this->transformations['city_to_code'] ?? [];
                return $map[$value] ?? $this->extractCodeFromCity($value);
                
            case 'city_to_port':
                if (!is_string($value) || $value === '') return $value;
                foreach (($this->transformations['city_to_port'] ?? []) as $city => $port) {
                    if (stripos($value, (string)$city) !== false) {
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
                
            case 'format_cargo':
                // format_cargo expects a composite input (array with keys)
                if (!is_array($value)) {
                    $value = ['quantity' => (string)$value];
                }
                Log::channel('robaws')->debug('format_cargo inputs', ['inputs' => $value]);
                return $this->transform_format_cargo($value);
                
            case 'format_cargo_core':
                if (!is_array($value)) $value = [];
                return $this->transform_format_cargo_core($value);
                
            case 'format_dimensions':
                Log::channel('robaws')->debug('format_dimensions input', ['value' => $value]);
                return $this->transform_format_dimensions($value);
                
            case 'normalize_city':
                return $this->transform_normalize_city($value);
                
            case 'normalize_customer_reference':
                return $this->transform_normalize_customer_reference($value, $fullData);
                
            case 'ensure_upper':
                return is_string($value) ? strtoupper($value) : $value;
                
            case 'format_simple_cargo':
                return $this->formatSimpleCargo($value, $fullData);
                
            case 'format_simple_reference':
                return $this->formatSimpleReference($value, $fullData);
                
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
        $map = $this->transformations['city_to_code'] ?? [];
        foreach ($map as $fullName => $code) {
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
    
    /**
     * Format cargo description from components (full line with qty + safe default)
     */
    private function transform_format_cargo(array $inputs): ?string
    {
        $qty = trim((string)($inputs['quantity'] ?? '1'));
        $core = $this->transform_format_cargo_core($inputs);
        return $core ? "{$qty} x {$core}" : "{$qty} x Vehicle";
    }

    /**
     * Core cargo formatter (no quantity, no default "Vehicle")
     */
    private function transform_format_cargo_core(array $inputs): ?string
    {
        $cond = trim((string)($inputs['condition'] ?? ''));
        $brand = trim((string)($inputs['brand'] ?? ''));
        $model = trim((string)($inputs['model'] ?? ''));
        $year = trim((string)($inputs['year'] ?? ''));

        $parts = array_values(array_filter([$cond ?: null, $brand ?: null, $model ?: null]));
        if (!$parts) return null;

        $core = implode(' ', $parts);
        return $year !== '' ? "{$core} ({$year})" : $core;
    }

    /**
     * Format dimensions from various inputs
     */
    private function transform_format_dimensions($value): ?string
    {
        // Accept already formatted strings
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        // Accept array/object with length/width/height (meters or mm/cm)
        $len = $this->pickFirstNumeric([
            $value['length_m'] ?? null,
            $value['length'] ?? null,
            $value['L'] ?? null,
            data_get($value, 'dimensions.length_m'),
        ]);
        $wid = $this->pickFirstNumeric([
            $value['width_m'] ?? null,
            $value['width'] ?? null,
            $value['W'] ?? null,
            data_get($value, 'dimensions.width_m'),
        ]);
        $hei = $this->pickFirstNumeric([
            $value['height_m'] ?? null,
            $value['height'] ?? null,
            $value['H'] ?? null,
            data_get($value, 'dimensions.height_m'),
        ]);

        $inferUnit = function ($raw) {
            if (is_string($raw)) {
                if (stripos($raw, 'mm') !== false) return 'mm';
                if (stripos($raw, 'cm') !== false) return 'cm';
                if (preg_match('/\bm(?!m)\b|meters?|meter/i', $raw)) return 'm';
            }
            return 'm'; // default to meters if unitless
        };

        $scale = function ($v, $unit) {
            if ($v === null) return null;
            $v = (float)$v;
            return match ($unit) {
                'mm' => $v / 1000,
                'cm' => $v / 100,
                default => $v, // meters
            };
        };

        $ulen = $inferUnit($value['length'] ?? ($value['length_m'] ?? ''));
        $uwid = $inferUnit($value['width']  ?? ($value['width_m']  ?? ''));
        $uhei = $inferUnit($value['height'] ?? ($value['height_m'] ?? ''));

        $len = $scale($len, $ulen);
        $wid = $scale($wid, $uwid);
        $hei = $scale($hei, $uhei);
        
        if ($len && $wid && $hei) {
            $volume = $len * $wid * $hei;
            
            // Try to get weight information
            $weight = $this->pickFirstNumeric([
                $value['weight_kg'] ?? null,
                $value['weight'] ?? null,
                data_get($value, 'vehicle.weight_kg'),
                data_get($value, 'cargo.weight'),
            ]);
            
            // Format with CBM, LM, and weight if available
            $formatParts = [sprintf('%.2f x %.2f x %.2f m', $len, $wid, $hei)];
            $formatParts[] = sprintf('%.2f Cbm', $volume);
            
            // Calculate LM (lanemeter) if width is 2.5m or higher
            if ($wid >= 2.5) {
                $lm = ($len * $wid) / 2.5;
                $formatParts[] = sprintf('%.2f LM', $lm);
            }
            
            if ($weight) {
                $formatParts[] = sprintf('%.0f kg', $weight);
            }
            
            return implode(' // ', $formatParts);
        }

        return null;
    }

    /**
     * Normalize city names
     */
    private function transform_normalize_city($value): ?string
    {
        if (!$value) return null;
        
        // Handle arrays (like origin.country)
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        
        if (!is_string($value) || $value === '') return is_scalar($value) ? (string)$value : null;
        $v = trim($value);
        // Normalize common variants
        $map = [
            'Bruxelles' => 'Brussels',
            'Brussels, Belgium' => 'Brussels',
            'Bruxelles, Belgique' => 'Brussels',
            'Djeddah' => 'Jeddah',
            'Jeddah, Saudi Arabia' => 'Jeddah',
            'Djeddah, Arabie Saoudite' => 'Jeddah',
            'Dubai, UAE' => 'Dubai',
            'Netherlands, Belgium' => 'Netherlands',
            'Nederland, België' => 'Netherlands',
            'Netherlands' => 'Netherlands',
            'Nederland' => 'Netherlands',
            'Belgium' => 'Belgium',
            'België' => 'Belgium',
            'Mersin' => 'Mersin',
            'Turkey' => 'Turkey',
            'Turkije' => 'Turkey',
            'Iraq' => 'Iraq',
            'Kurdistan' => 'Kurdistan',
            'Koerdistan' => 'Kurdistan',
        ];
        return $map[$v] ?? $v;
    }

    /**
     * Normalize customer reference - keep existing reference if it already has codes
     */
    private function transform_normalize_customer_reference($value, array $fullData): ?string
    {
        if (!is_string($value)) return null;
        $v = trim(preg_replace('/\s+/', ' ', $value));

        // Keep if it already has at least two 3-letter codes
        if (preg_match_all('/\b[A-Z]{3}\b/', strtoupper($v), $m) && count($m[0]) >= 2) {
            return $v;
        }

        // If it's only the bare prefix (e.g. "EXP RORO"), make it blank → triggers template fallback
        if (preg_match('/^EXP\s+RORO\b/i', $v)) {
            return '';
        }

        return $v !== '' ? $v : null;
    }

    /**
     * Helper to pick first numeric value from candidates
     */
    private function pickFirstNumeric(array $candidates): ?float
    {
        foreach ($candidates as $c) {
            if ($c === null) continue;
            if (is_numeric($c)) return (float) $c;
            if (is_string($c) && preg_match('/[\d.]+/', $c, $m)) {
                return (float) $m[0];
            }
        }
        return null;
    }
    
    /**
     * Format simple cargo description from vehicle make/model
     */
    private function formatSimpleCargo(mixed $value, array $fullData): ?string
    {
        // Try to get vehicle details from the full data
        $make = data_get($fullData, 'raw_data.expedition.vehicle.make');
        $model = data_get($fullData, 'raw_data.expedition.vehicle.model');
        $cargoType = data_get($fullData, 'raw_data.request.cargo.type');
        $cargoQuantity = data_get($fullData, 'raw_data.request.cargo.quantity');
        $transportCargoDesc = data_get($fullData, 'raw_data.transport_details.cargo.description');
        $transportCargoQty = data_get($fullData, 'raw_data.transport_details.cargo.quantity');
        
        // Check if vehicle is mentioned as "new" in the data
        $isNew = $this->isVehicleNew($fullData);
        $condition = $isNew ? 'new' : 'used';
        
        if ($make && $model) {
            return "1 x {$condition} {$make} {$model}";
        }
        
        if ($transportCargoDesc && $transportCargoQty) {
            return "{$transportCargoQty} x {$condition} {$transportCargoDesc}";
        }
        
        if ($transportCargoDesc) {
            return "1 x {$condition} {$transportCargoDesc}";
        }
        
        if ($cargoType && $cargoQuantity) {
            return "{$cargoQuantity} x {$condition} {$cargoType}";
        }
        
        if ($cargoType) {
            return "1 x {$condition} {$cargoType}";
        }
        
        // Fallback to the value if it's a string
        if (is_string($value) && !empty($value)) {
            return "1 x {$condition} {$value}";
        }
        
        return "1 x {$condition} Vehicle";
    }
    
    /**
     * Format simple customer reference
     */
    private function formatSimpleReference(mixed $value, array $fullData): ?string
    {
        // Try to get origin, POL, and destination codes
        $origin = data_get($fullData, 'raw_data.expedition.origin');
        $pol = data_get($fullData, 'raw_data.expedition.pol') ?? 'Antwerp'; // Default to Antwerp if not specified
        $destination = data_get($fullData, 'raw_data.expedition.destination');
        $make = data_get($fullData, 'raw_data.expedition.vehicle.make');
        $model = data_get($fullData, 'raw_data.expedition.vehicle.model');
        
        // Convert cities to codes
        $originCode = $this->cityToCode($origin);
        $polCode = $this->cityToCode($pol);
        $destinationCode = $this->cityToCode($destination);
        
        // Check if vehicle is mentioned as "new" in the data
        $isNew = $this->isVehicleNew($fullData);
        $condition = $isNew ? 'new' : 'used';
        
        $cargoDesc = '';
        if ($make && $model) {
            $cargoDesc = "1 x {$condition} {$make} {$model}";
        } elseif ($make) {
            $cargoDesc = "1 x {$condition} {$make}";
        } else {
            $cargoDesc = "1 x {$condition} Vehicle";
        }
        
        return "EXP RORO - {$originCode} - {$polCode} - {$destinationCode} - {$cargoDesc}";
    }
    
    /**
     * Check if vehicle is mentioned as "new" in the data
     */
    private function isVehicleNew(array $fullData): bool
    {
        // Check various fields for "new" indication
        $fieldsToCheck = [
            'raw_data.expedition.vehicle.condition',
            'raw_data.expedition.vehicle.status',
            'raw_data.expedition.vehicle.type',
            'raw_data.expedition.cargo.description',
            'raw_data.expedition.cargo.type',
        ];
        
        foreach ($fieldsToCheck as $field) {
            $value = data_get($fullData, $field);
            if (is_string($value) && stripos($value, 'new') !== false) {
                return true;
            }
        }
        
        // Default to "used" if not explicitly mentioned as "new"
        return false;
    }
    
    /**
     * Convert city name to code
     */
    private function cityToCode($city): string
    {
        if (!$city) return '';
        
        // Handle arrays (like origin.country)
        if (is_array($city)) {
            $city = implode(', ', $city);
        }
        
        $map = [
            'Brussels' => 'BRU',
            'Bruxelles' => 'BRU',
            'Antwerp' => 'ANR',
            'Anvers' => 'ANR',
            'Jeddah' => 'JED',
            'Djeddah' => 'JED',
            'Mersin' => 'MER',
            'Turkey' => 'TUR',
            'Turkije' => 'TUR',
            'Netherlands' => 'NLD',
            'Nederland' => 'NLD',
            'Belgium' => 'BEL',
            'België' => 'BEL',
            'Iraq' => 'IRQ',
            'Kurdistan' => 'KUR',
            'Koerdistan' => 'KUR',
        ];
        
        return $map[$city] ?? strtoupper(substr($city, 0, 3));
    }
}

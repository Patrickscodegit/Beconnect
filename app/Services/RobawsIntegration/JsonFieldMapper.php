<?php

namespace App\Services\RobawsIntegration;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class JsonFieldMapper
{
    private array $mappingConfig;
    private array $transformations;
    private array $validationRules;
    private ?string $currentFieldName = null;
    
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
                // Set current field name for context-aware transformations
                $this->currentFieldName = $targetField;
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
                // Handle arrays (like origin.country) - take the first element
                if (is_array($value)) {
                    $value = $value[0] ?? '';
                }
                
                if (!is_string($value) || $value === '') return $value;
                
                $map = $this->transformations['city_to_code'] ?? [];
                return $map[$value] ?? $this->extractCodeFromCity($value);
                
            case 'city_to_port':
                // Handle arrays (like origin.country) - take the first element
                if (is_array($value)) {
                    $value = $value[0] ?? '';
                }
                
                if (!is_string($value) || $value === '') return $value;
                
                // Special handling for Beverly Hills Car Club -> Los Angeles
                if (stripos($value, 'Beverly Hills Car Club') !== false) {
                    return 'Los Angeles';
                }
                
                // European countries - always use Antwerp as POL
                $europeanCountries = ['Nederland', 'Netherlands', 'België', 'Belgium', 'Germany', 'Duitsland', 'France', 'Frankrijk', 'Italy', 'Italië', 'Spain', 'Spanje', 'Portugal', 'Austria', 'Oostenrijk', 'Switzerland', 'Zwitserland', 'Denmark', 'Denemarken', 'Sweden', 'Zweden', 'Norway', 'Noorwegen', 'Finland', 'Finland', 'Poland', 'Polen', 'Czech Republic', 'Tsjechië', 'Hungary', 'Hongarije', 'Slovakia', 'Slowakije', 'Slovenia', 'Slovenië', 'Croatia', 'Kroatië', 'Romania', 'Roemenië', 'Bulgaria', 'Bulgarije', 'Greece', 'Griekenland', 'Ireland', 'Ierland', 'United Kingdom', 'Verenigd Koninkrijk', 'Luxembourg', 'Luxemburg'];
                
                foreach ($europeanCountries as $country) {
                    if (stripos($value, $country) !== false) {
                        return 'Antwerp, Belgium';
                    }
                }
                
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
                return $this->transform_format_dimensions($value, $fullData);
                
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
                
            case 'format_contact_textarea':
                return $this->formatContactTextarea($value, $fullData, $this->currentFieldName ?? null);
                
            case 'extract_cargo_summary':
                return $this->transform_extract_cargo_summary($value);
                
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
     * Extract cargo summary for customer reference (first line only)
     */
    private function transform_extract_cargo_summary($value): ?string
    {
        if (!$value) return null;
        
        // If it's already a string, take the first line
        if (is_string($value)) {
            $lines = explode("\n", $value);
            return trim($lines[0]);
        }
        
        // If it's an array, try to build a summary
        if (is_array($value)) {
            $parts = [];
            if (!empty($value['quantity'])) $parts[] = $value['quantity'];
            if (!empty($value['condition'])) $parts[] = $value['condition'];
            if (!empty($value['brand'])) $parts[] = $value['brand'];
            if (!empty($value['model'])) $parts[] = $value['model'];
            if (!empty($value['type'])) $parts[] = $value['type'];
            
            return !empty($parts) ? implode(' ', $parts) : null;
        }
        
        return null;
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
    private function transform_format_dimensions($value, array $fullData = []): ?string
    {
        // Accept already formatted strings (but not vehicle names)
        if (is_string($value) && trim($value) !== '') {
            // Check if it's already a dimension string (contains 'x' and 'm')
            if (strpos($value, ' x ') !== false && strpos($value, ' m') !== false) {
                return $value;
            }
            // If it's a vehicle name, continue to dimension lookup
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

        // Try to get dimensions from vehicle database or OpenAI
        // Use fullData to get vehicle information
        $make = data_get($fullData, 'raw_data.expedition.vehicle.make') ?? 
                data_get($fullData, 'raw_data.vehicle_make') ?? 
                data_get($fullData, 'vehicle_make');
        $model = data_get($fullData, 'raw_data.expedition.vehicle.model') ?? 
                 data_get($fullData, 'raw_data.vehicle_model') ?? 
                 data_get($fullData, 'vehicle_model');
        $year = data_get($fullData, 'raw_data.vehicle_year') ?? data_get($fullData, 'vehicle_year');
        
        // Enhanced vehicle dimension lookup with fallbacks
        if ($make && $model) {
            // Try with year if available
            if ($year) {
                $dimensions = $this->getVehicleDimensions($make, $model, $year);
                if ($dimensions) {
                    return $dimensions;
                }
            }
            
            // Try without year (more flexible)
            $dimensions = $this->getVehicleDimensions($make, $model, null);
            if ($dimensions) {
                return $dimensions;
            }
        }
        
        // Try to extract vehicle info from cargo description if not found
        $cargoDescription = data_get($fullData, 'raw_data.cargo_description') ?? 
                           data_get($fullData, 'cargo_description') ?? 
                           data_get($fullData, 'raw_data.JSON.cargo_description');
        
        if ($cargoDescription && is_string($cargoDescription)) {
            $extractedVehicle = $this->extractVehicleFromDescription($cargoDescription);
            if ($extractedVehicle['make'] && $extractedVehicle['model']) {
                $dimensions = $this->getVehicleDimensions(
                    $extractedVehicle['make'], 
                    $extractedVehicle['model'], 
                    $extractedVehicle['year']
                );
                if ($dimensions) {
                    return $dimensions;
                }
            }
        }

        return null;
    }

    /**
     * Get vehicle dimensions from database or OpenAI
     */
    private function getVehicleDimensions(string $make, string $model, ?string $year = null): ?string
    {
        try {
            // Normalize make and model for better matching
            $normalizedMake = $this->normalizeVehicleMake($make);
            $normalizedModel = $this->normalizeVehicleModel($model);
            
            // First try the vehicle database (exact match with year)
            if ($year) {
                $vehicle = \App\Models\VehicleSpec::where('make', $normalizedMake)
                    ->where('model', $normalizedModel)
                    ->where('year', (int)$year)
                    ->first();
                
                if ($vehicle && $vehicle->length_m && $vehicle->width_m && $vehicle->height_m) {
                    $volume = $vehicle->length_m * $vehicle->width_m * $vehicle->height_m;
                    $formattedDimensions = sprintf('%.2f x %.2f x %.2f m // %.2f Cbm', 
                        $vehicle->length_m, $vehicle->width_m, $vehicle->height_m, $volume);
                    
                    // Log successful database lookup
                    Log::info('Vehicle dimensions retrieved from database', [
                        'make' => $normalizedMake,
                        'model' => $normalizedModel,
                        'year' => $year,
                        'dimensions' => $formattedDimensions,
                        'source' => 'database',
                        'vehicle_id' => $vehicle->id
                    ]);
                    
                    return $formattedDimensions;
                }
            }
            
            // Try database without year (fuzzy match)
            $vehicle = \App\Models\VehicleSpec::where('make', $normalizedMake)
                ->where('model', $normalizedModel)
                ->orderBy('year', 'desc')
                ->first();
            
            if ($vehicle && $vehicle->length_m && $vehicle->width_m && $vehicle->height_m) {
                $volume = $vehicle->length_m * $vehicle->width_m * $vehicle->height_m;
                $formattedDimensions = sprintf('%.2f x %.2f x %.2f m // %.2f Cbm', 
                    $vehicle->length_m, $vehicle->width_m, $vehicle->height_m, $volume);
                
                // Log successful database lookup (fuzzy match)
                Log::info('Vehicle dimensions retrieved from database (fuzzy match)', [
                    'make' => $normalizedMake,
                    'model' => $normalizedModel,
                    'year' => $year,
                    'dimensions' => $formattedDimensions,
                    'source' => 'database_fuzzy',
                    'vehicle_id' => $vehicle->id,
                    'matched_year' => $vehicle->year
                ]);
                
                return $formattedDimensions;
            }
            
            // Check cache first to avoid repeated OpenAI calls
            $cacheKey = "vehicle_dimensions_{$normalizedMake}_{$normalizedModel}" . ($year ? "_{$year}" : '');
            $cachedDimensions = \Cache::get($cacheKey);
            if ($cachedDimensions) {
                // Log cache hit
                Log::info('Vehicle dimensions retrieved from cache', [
                    'make' => $normalizedMake,
                    'model' => $normalizedModel,
                    'year' => $year,
                    'dimensions' => $cachedDimensions,
                    'source' => 'cache'
                ]);
                return $cachedDimensions;
            }
            
            // Fallback to OpenAI for accurate dimensions
            $openai = \OpenAI::client(config('services.openai.api_key'));
            
            $yearText = $year ? "{$year} " : '';
            $prompt = "What are the exact dimensions (length, width, height) in meters for a {$yearText}{$normalizedMake} {$normalizedModel}? Please provide the measurements in meters with 2 decimal places in the format: LENGTH x WIDTH x HEIGHT m";
            
            $response = $openai->chat()->create([
                'model' => 'gpt-4',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 100
            ]);
            
            $dimensions = trim($response->choices[0]->message->content);
            
            // Parse the response to extract dimensions
            if (preg_match('/(\d+\.?\d*)\s*x\s*(\d+\.?\d*)\s*x\s*(\d+\.?\d*)\s*m/i', $dimensions, $matches)) {
                $length = (float)$matches[1];
                $width = (float)$matches[2];
                $height = (float)$matches[3];
                
                // Validate dimensions
                $validation = $this->validateVehicleDimensions($length, $width, $height, $normalizedMake, $normalizedModel);
                if (!$validation['valid']) {
                    Log::warning('Invalid vehicle dimensions from OpenAI', [
                        'make' => $normalizedMake,
                        'model' => $normalizedModel,
                        'year' => $year,
                        'dimensions' => "{$length} x {$width} x {$height} m",
                        'reason' => $validation['reason']
                    ]);
                    
                    // Return null to fall back to default
                    return null;
                }
                
                $volume = $length * $width * $height;
                $formattedDimensions = sprintf('%.2f x %.2f x %.2f m // %.2f Cbm', $length, $width, $height, $volume);
                
                // Log successful OpenAI lookup
                Log::info('Vehicle dimensions retrieved from OpenAI', [
                    'make' => $normalizedMake,
                    'model' => $normalizedModel,
                    'year' => $year,
                    'dimensions' => $formattedDimensions,
                    'source' => 'openai'
                ]);
                
                // Cache the result for 24 hours
                \Cache::put($cacheKey, $formattedDimensions, 86400);
                
                return $formattedDimensions;
            }
            
        } catch (\Exception $e) {
            Log::warning('Failed to get vehicle dimensions', [
                'make' => $make,
                'model' => $model,
                'year' => $year,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Normalize vehicle make for better database matching
     */
    private function normalizeVehicleMake(string $make): string
    {
        $make = trim($make);
        
        // Common make normalizations
        $normalizations = [
            'BMW' => 'BMW',
            'Mercedes-Benz' => 'Mercedes-Benz',
            'Mercedes' => 'Mercedes-Benz',
            'Audi' => 'Audi',
            'Volkswagen' => 'Volkswagen',
            'VW' => 'Volkswagen',
            'Toyota' => 'Toyota',
            'Honda' => 'Honda',
            'Ford' => 'Ford',
            'Chevrolet' => 'Chevrolet',
            'Chevy' => 'Chevrolet',
        ];
        
        return $normalizations[$make] ?? $make;
    }

    /**
     * Normalize vehicle model for better database matching
     */
    private function normalizeVehicleModel(string $model): string
    {
        $model = trim($model);
        
        // Common model normalizations
        $normalizations = [
            'Série 7' => '7 Series',
            'Serie 7' => '7 Series',
            '7-Series' => '7 Series',
            '7_Series' => '7 Series',
            'Série 5' => '5 Series',
            'Serie 5' => '5 Series',
            '5-Series' => '5 Series',
            '5_Series' => '5 Series',
            'Série 3' => '3 Series',
            'Serie 3' => '3 Series',
            '3-Series' => '3 Series',
            '3_Series' => '3 Series',
            'S-Class' => 'S-Class',
            'S_Class' => 'S-Class',
            'E-Class' => 'E-Class',
            'E_Class' => 'E-Class',
            'C-Class' => 'C-Class',
            'C_Class' => 'C-Class',
            'A8' => 'A8',
            'A6' => 'A6',
            'A4' => 'A4',
        ];
        
        return $normalizations[$model] ?? $model;
    }

    /**
     * Extract vehicle information from cargo description
     */
    private function extractVehicleFromDescription(string $description): array
    {
        $result = ['make' => null, 'model' => null, 'year' => null];
        
        // Common patterns for vehicle extraction
        $patterns = [
            // "1 x used BMW Série 7"
            '/(\d+)\s*x\s*(?:used|new)?\s*([A-Za-z]+)\s+([A-Za-z0-9\s\-_]+)/i',
            // "BMW 7 Series"
            '/([A-Za-z]+)\s+([A-Za-z0-9\s\-_]+)/i',
            // "2020 BMW 7 Series"
            '/(\d{4})\s+([A-Za-z]+)\s+([A-Za-z0-9\s\-_]+)/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $description, $matches)) {
                if (count($matches) >= 4) {
                    // Pattern with year
                    $result['year'] = $matches[1];
                    $result['make'] = $matches[2];
                    $result['model'] = trim($matches[3]);
                } elseif (count($matches) >= 3) {
                    // Pattern without year
                    $result['make'] = $matches[1];
                    $result['model'] = trim($matches[2]);
                }
                break;
            }
        }
        
        // Try to extract year separately if not found
        if (!$result['year'] && preg_match('/(\d{4})/', $description, $yearMatches)) {
            $result['year'] = $yearMatches[1];
        }
        
        return $result;
    }

    /**
     * Normalize city names
     */
    private function transform_normalize_city($value): ?string
    {
        if (!$value) return null;
        
        // Handle arrays (like origin.country) - take the first country
        if (is_array($value)) {
            $value = $value[0] ?? '';
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
        // Try to get vehicle details from the full data (EML structure)
        $make = data_get($fullData, 'raw_data.expedition.vehicle.make');
        $model = data_get($fullData, 'raw_data.expedition.vehicle.model');
        $year = data_get($fullData, 'raw_data.expedition.vehicle.year');
        $cargoType = data_get($fullData, 'raw_data.request.cargo.type');
        $cargoQuantity = data_get($fullData, 'raw_data.request.cargo.quantity');
        $transportCargoDesc = data_get($fullData, 'raw_data.transport_details.cargo.description');
        $transportCargoQty = data_get($fullData, 'raw_data.transport_details.cargo.quantity');
        
        // Try to get vehicle details from new EML structure (HybridExtractionPipeline)
        $newMake = data_get($fullData, 'vehicle.brand');
        $newModel = data_get($fullData, 'vehicle.model');
        $newYear = data_get($fullData, 'vehicle.year');
        
        // Try to get vehicle details from image data structure
        $imageMake = data_get($fullData, 'raw_data.vehicle_make');
        $imageModel = data_get($fullData, 'raw_data.vehicle_model');
        $imageYear = data_get($fullData, 'raw_data.vehicle_year');
        $imageCargoDesc = data_get($fullData, 'raw_data.cargo_description');
        $imageQuantity = data_get($fullData, 'raw_data.quantity');
        
        // Try to get year from JSON structure
        $jsonYear = data_get($fullData, 'raw_data.JSON');
        if ($jsonYear && is_string($jsonYear)) {
            $jsonData = json_decode($jsonYear, true);
            if (isset($jsonData['vehicle_specifications']['year'])) {
                $year = $year ?: $jsonData['vehicle_specifications']['year'];
            }
        }
        
        // Use new EML structure first, then fallback to old structures
        $make = $newMake ?: $make ?: $imageMake;
        $model = $newModel ?: $model ?: $imageModel;
        $year = $newYear ?: $year ?: $imageYear;
        $cargoType = $cargoType ?: $imageCargoDesc;
        $cargoQuantity = $cargoQuantity ?: $imageQuantity;
        
        // Check if vehicle is mentioned as "new" in the data
        $isNew = $this->isVehicleNew($fullData);
        $condition = $isNew ? 'new' : 'used';
        
        if ($make && $model) {
            // Only include year if it's explicitly mentioned in the email content
            $yearText = '';
            if ($year && $this->isYearMentionedInContent($year, $fullData)) {
                $yearText = " {$year}";
            }
            return "1 x {$condition} {$make} {$model}{$yearText}";
        }
        
        if ($transportCargoDesc && $transportCargoQty) {
            // Check if description already contains "used" or "new" to avoid duplication
            $cleanDesc = $transportCargoDesc;
            if (stripos($cleanDesc, 'used') !== false) {
                $cleanDesc = preg_replace('/\bused\b/i', '', $cleanDesc);
                $cleanDesc = trim($cleanDesc);
            }
            if (stripos($cleanDesc, 'new') !== false) {
                $cleanDesc = preg_replace('/\bnew\b/i', '', $cleanDesc);
                $cleanDesc = trim($cleanDesc);
            }
            return "{$transportCargoQty} x {$condition} {$cleanDesc}";
        }
        
        if ($transportCargoDesc) {
            // Check if description already contains "used" or "new" to avoid duplication
            $cleanDesc = $transportCargoDesc;
            if (stripos($cleanDesc, 'used') !== false) {
                $cleanDesc = preg_replace('/\bused\b/i', '', $cleanDesc);
                $cleanDesc = trim($cleanDesc);
            }
            if (stripos($cleanDesc, 'new') !== false) {
                $cleanDesc = preg_replace('/\bnew\b/i', '', $cleanDesc);
                $cleanDesc = trim($cleanDesc);
            }
            return "1 x {$condition} {$cleanDesc}";
        }
        
        if ($cargoType && $cargoQuantity) {
            // Check if cargo type already contains "used" or "new" to avoid duplication
            $cleanCargoType = $cargoType;
            if (stripos($cleanCargoType, 'used') !== false) {
                $cleanCargoType = preg_replace('/\bused\b/i', '', $cleanCargoType);
                $cleanCargoType = trim($cleanCargoType);
            }
            if (stripos($cleanCargoType, 'new') !== false) {
                $cleanCargoType = preg_replace('/\bnew\b/i', '', $cleanCargoType);
                $cleanCargoType = trim($cleanCargoType);
            }
            return "{$cargoQuantity} x {$condition} {$cleanCargoType}";
        }
        
        if ($cargoType) {
            // Check if cargo type already contains "used" or "new" to avoid duplication
            $cleanCargoType = $cargoType;
            if (stripos($cleanCargoType, 'used') !== false) {
                $cleanCargoType = preg_replace('/\bused\b/i', '', $cleanCargoType);
                $cleanCargoType = trim($cleanCargoType);
            }
            if (stripos($cleanCargoType, 'new') !== false) {
                $cleanCargoType = preg_replace('/\bnew\b/i', '', $cleanCargoType);
                $cleanCargoType = trim($cleanCargoType);
            }
            return "1 x {$condition} {$cleanCargoType}";
        }
        
        // Fallback to the value if it's a string
        if (is_string($value) && !empty($value)) {
            // Check if the value already contains "1 x" and condition to avoid duplication
            $cleanValue = $value;
            if (preg_match('/^1\s+x\s+(used|new)\s+/i', $cleanValue)) {
                // Already formatted, return as is
                return $cleanValue;
            }
            // Remove any existing "used" or "new" to avoid duplication
            if (stripos($cleanValue, 'used') !== false) {
                $cleanValue = preg_replace('/\bused\b/i', '', $cleanValue);
                $cleanValue = trim($cleanValue);
            }
            if (stripos($cleanValue, 'new') !== false) {
                $cleanValue = preg_replace('/\bnew\b/i', '', $cleanValue);
                $cleanValue = trim($cleanValue);
            }
            return "1 x {$condition} {$cleanValue}";
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
     * Check if year is explicitly mentioned in the email content
     */
    private function isYearMentionedInContent(string $year, array $data): bool
    {
        // Get the raw email content from various possible fields
        $content = data_get($data, 'raw_text') 
            ?? data_get($data, 'raw_data.raw_text')
            ?? data_get($data, 'raw_data.body')
            ?? data_get($data, 'raw_data.content')
            ?? data_get($data, 'raw_data.email_content')
            ?? data_get($data, 'metadata.raw_text')
            ?? '';
        
        // Check if the year appears in the content
        if (empty($content)) {
            return false;
        }
        
        // Look for the year in the content (with word boundaries to avoid partial matches)
        return preg_match('/\b' . preg_quote($year, '/') . '\b/', $content) === 1;
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
        
        // Handle arrays (like origin.country) - take the first country
        if (is_array($city)) {
            $city = $city[0] ?? '';
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

    /**
     * Validate vehicle dimensions for sanity checks
     */
    private function validateVehicleDimensions(float $length, float $width, float $height, string $make, string $model): array
    {
        // Basic sanity checks
        if ($length < 2.0 || $length > 8.0) {
            return ['valid' => false, 'reason' => "Length {$length}m is outside reasonable range (2-8m)"];
        }
        
        if ($width < 1.0 || $width > 3.0) {
            return ['valid' => false, 'reason' => "Width {$width}m is outside reasonable range (1-3m)"];
        }
        
        if ($height < 1.0 || $height > 3.0) {
            return ['valid' => false, 'reason' => "Height {$height}m is outside reasonable range (1-3m)"];
        }
        
        // Luxury sedan specific validation
        $luxuryMakes = ['BMW', 'Mercedes-Benz', 'Audi', 'Lexus', 'Porsche', 'Jaguar', 'Bentley', 'Rolls-Royce'];
        $luxuryModels = ['7 Series', 'S-Class', 'A8', 'LS', 'Panamera', 'XJ', 'Continental', 'Phantom'];
        
        $isLuxuryMake = in_array($make, $luxuryMakes);
        $isLuxuryModel = in_array($model, $luxuryModels);
        
        if ($isLuxuryMake || $isLuxuryModel) {
            // Luxury sedans should be at least 4.5m long
            if ($length < 4.5) {
                return ['valid' => false, 'reason' => "Luxury sedan {$make} {$model} length {$length}m is too short (minimum 4.5m)"];
            }
            
            // Luxury sedans should be at least 1.8m wide
            if ($width < 1.8) {
                return ['valid' => false, 'reason' => "Luxury sedan {$make} {$model} width {$width}m is too narrow (minimum 1.8m)"];
            }
        }
        
        // Volume sanity check (should be reasonable for a vehicle)
        $volume = $length * $width * $height;
        if ($volume < 5.0 || $volume > 50.0) {
            return ['valid' => false, 'reason' => "Volume {$volume}m³ is outside reasonable range (5-50m³)"];
        }
        
        return ['valid' => true, 'reason' => 'Dimensions passed all validation checks'];
    }
    
    /**
     * Format contact information for textarea fields
     */
    private function formatContactTextarea($value, array $fullData, ?string $fieldName = null): ?string
    {
        if (!$value) return null;
        
        // If it's already a formatted string, return as-is
        if (is_string($value) && strpos($value, "\n") !== false) {
            return $value;
        }
        
        // Extract contact information from full data
        $name = null;
        $address = null;
        $email = null;
        $phone = null;
        
        // Try to get name from the value or full data
        if (is_string($value)) {
            $name = trim($value);
        }
        
        // Determine which contact type to use based on the field name
        $contactData = null;
        
        if ($fieldName) {
            // Select contact data based on field name
            if (strpos($fieldName, 'shipper') !== false) {
                $contactData = $fullData['contact'] ?? null;
            } elseif (strpos($fieldName, 'consignee') !== false) {
                $contactData = $fullData['consignee'] ?? null;
            } elseif (strpos($fieldName, 'notify') !== false) {
                $contactData = $fullData['notify'] ?? null;
            }
        }
        
        // Fallback: try to match by name content if field context is unclear
        if (!$contactData) {
            if (isset($fullData['contact']) && is_array($fullData['contact']) && 
                isset($fullData['contact']['name']) && 
                strpos($name, $fullData['contact']['name']) !== false) {
                $contactData = $fullData['contact'];
            } elseif (isset($fullData['consignee']) && is_array($fullData['consignee']) && 
                      isset($fullData['consignee']['name']) && 
                      strpos($name, $fullData['consignee']['name']) !== false) {
                $contactData = $fullData['consignee'];
            } elseif (isset($fullData['notify']) && is_array($fullData['notify']) && 
                      isset($fullData['notify']['name']) && 
                      strpos($name, $fullData['notify']['name']) !== false) {
                $contactData = $fullData['notify'];
            }
        }
        
        // Extract contact details from the appropriate source
        if ($contactData && is_array($contactData)) {
            $name = $name ?: ($contactData['name'] ?? null);
            $address = $contactData['address'] ?? null;
            $email = $contactData['email'] ?? null;
            $phone = $contactData['phone'] ?? null;
        }
        
        // Build textarea content
        $lines = [];
        if ($name) $lines[] = $name;
        if ($address) {
            if (is_array($address)) {
                // Format address array as a single line
                $addressParts = array_filter([
                    $address['street'] ?? null,
                    $address['city'] ?? null,
                    $address['country'] ?? null
                ]);
                if (!empty($addressParts)) {
                    $lines[] = implode(', ', $addressParts);
                }
            } else {
                $lines[] = $address;
            }
        }
        if ($email) $lines[] = $email;
        if ($phone) $lines[] = $phone;
        
        return $lines ? implode("\n", $lines) : $value;
    }
}

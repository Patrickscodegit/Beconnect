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
        
        foreach ($this->mappingConfig as $section => $fields) {
            foreach ($fields as $targetField => $config) {
                $value = $this->extractFieldValue($extractedData, $config);
                $result[$targetField] = $value;
            }
        }
        
        // Add computed fields
        $result['formatted_at'] = now()->toISOString();
        $result['source'] = 'bconnect_ai_extraction';
        $result['mapping_version'] = $this->getConfigVersion();
        
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
}

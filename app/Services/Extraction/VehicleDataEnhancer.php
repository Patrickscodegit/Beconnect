<?php

namespace App\Services\Extraction;

use App\Services\VehicleDatabase\VehicleDatabaseService;
use App\Services\AiRouter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class VehicleDataEnhancer
{
    public function __construct(
        private VehicleDatabaseService $vehicleDb,
        private AiRouter $aiRouter
    ) {}

    /**
     * Enhance extracted data with database and AI-generated vehicle specifications
     *
     * @param array $extractedData The data extracted from the document
     * @param array $metadata Additional metadata about the extraction
     * @return array Enhanced data with clear source attribution
     */
    public function enhance(array $extractedData, array $metadata = []): array
    {
        $startTime = microtime(true);
        
        // Initialize source tracking
        $sources = [
            'document_extracted' => $this->getExtractedFields($extractedData),
            'database_enhanced' => [],
            'ai_enhanced' => [],
            'calculated' => []
        ];
        
        // Log enhancement start
        Log::info('Starting vehicle data enhancement', [
            'has_vehicle_data' => isset($extractedData['vehicle']),
            'document_id' => $metadata['document_id'] ?? null,
            'extraction_strategy' => $metadata['extraction_strategy'] ?? null
        ]);
        
        // Only enhance if we have vehicle data
        if (!empty($extractedData['vehicle']) && $this->canEnhanceVehicle($extractedData['vehicle'])) {
            // Step 1: Database enhancement
            $extractedData = $this->enhanceFromDatabase($extractedData, $sources);
            
            // Step 2: AI enhancement for missing critical specs
            if ($this->needsAiEnhancement($extractedData['vehicle'])) {
                $extractedData = $this->enhanceFromAI($extractedData, $sources);
            }
            
            // Step 3: Calculate derived fields
            $extractedData = $this->calculateDerivedFields($extractedData, $sources);
        }
        
        // Add enhancement metadata
        $extractedData['data_sources'] = $sources;
        $extractedData['enhancement_metadata'] = [
            'enhanced_at' => now()->toIso8601String(),
            'enhancement_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'sources_used' => $this->getSourcesSummary($sources),
            'confidence' => $this->calculateEnhancementConfidence($sources)
        ];
        
        Log::info('Vehicle data enhancement completed', [
            'enhancement_time_ms' => $extractedData['enhancement_metadata']['enhancement_time_ms'],
            'fields_enhanced' => array_merge(
                $sources['database_enhanced'],
                $sources['ai_enhanced'],
                $sources['calculated']
            )
        ]);
        
        return $extractedData;
    }

    /**
     * Get list of fields extracted from the original document
     */
    private function getExtractedFields(array $data): array
    {
        $fields = [];
        $flattened = Arr::dot($data);
        
        foreach ($flattened as $key => $value) {
            if ($value !== null && !is_array($value)) {
                $fields[] = $key;
            }
        }
        
        return $fields;
    }

    /**
     * Check if vehicle data can be enhanced
     */
    private function canEnhanceVehicle(array $vehicle): bool
    {
        return !empty($vehicle['make']) || !empty($vehicle['model']) || !empty($vehicle['year']);
    }

    /**
     * Enhance vehicle data from database
     */
    private function enhanceFromDatabase(array $extractedData, array &$sources): array
    {
        $vehicle = $extractedData['vehicle'];
        
        Log::info('Attempting database enhancement', [
            'make' => $vehicle['make'] ?? null,
            'model' => $vehicle['model'] ?? null,
            'year' => $vehicle['year'] ?? null
        ]);
        
        try {
            // Use VehicleDatabaseService to find matching vehicle
            $searchParams = [
                'make' => $vehicle['make'] ?? null,
                'model' => $vehicle['model'] ?? null,
                'year' => $vehicle['year'] ?? null
            ];
            
            $dbVehicle = $this->vehicleDb->findVehicle($searchParams);
            
            if ($dbVehicle) {
                // Merge database specs while preserving document data
                $enhancedVehicle = $vehicle;
                $dbFields = [];
                
                // Add engine specifications
                if (!empty($dbVehicle->engine_cc) && empty($vehicle['engine_cc'])) {
                    $enhancedVehicle['engine_cc'] = $dbVehicle->engine_cc;
                    $dbFields[] = 'vehicle.engine_cc';
                }
                
                if (!empty($dbVehicle->fuel_type) && empty($vehicle['fuel_type'])) {
                    $enhancedVehicle['fuel_type'] = $dbVehicle->fuel_type;
                    $dbFields[] = 'vehicle.fuel_type';
                }
                
                // Add dimensions
                if (!empty($dbVehicle->length_m) || !empty($dbVehicle->width_m) || !empty($dbVehicle->height_m)) {
                    if (empty($vehicle['dimensions'])) {
                        $enhancedVehicle['dimensions'] = [];
                    }
                    
                    if (!empty($dbVehicle->length_m) && empty($vehicle['dimensions']['length'])) {
                        $enhancedVehicle['dimensions']['length'] = $dbVehicle->length_m;
                        $enhancedVehicle['dimensions']['unit'] = 'm';
                        $dbFields[] = 'vehicle.dimensions.length';
                    }
                    
                    if (!empty($dbVehicle->width_m) && empty($vehicle['dimensions']['width'])) {
                        $enhancedVehicle['dimensions']['width'] = $dbVehicle->width_m;
                        $enhancedVehicle['dimensions']['unit'] = 'm';
                        $dbFields[] = 'vehicle.dimensions.width';
                    }
                    
                    if (!empty($dbVehicle->height_m) && empty($vehicle['dimensions']['height'])) {
                        $enhancedVehicle['dimensions']['height'] = $dbVehicle->height_m;
                        $enhancedVehicle['dimensions']['unit'] = 'm';
                        $dbFields[] = 'vehicle.dimensions.height';
                    }
                }
                
                // Add weight
                if (!empty($dbVehicle->weight_kg) && empty($vehicle['weight']['value'])) {
                    if (empty($vehicle['weight'])) {
                        $enhancedVehicle['weight'] = [];
                    }
                    $enhancedVehicle['weight']['value'] = $dbVehicle->weight_kg;
                    $enhancedVehicle['weight']['unit'] = 'kg';
                    $dbFields[] = 'vehicle.weight.value';
                }
                
                // Add wheelbase
                if (!empty($dbVehicle->wheelbase_m) && empty($vehicle['wheelbase_m'])) {
                    $enhancedVehicle['wheelbase_m'] = $dbVehicle->wheelbase_m;
                    $dbFields[] = 'vehicle.wheelbase_m';
                }
                
                $sources['database_enhanced'] = $dbFields;
                $extractedData['vehicle'] = $enhancedVehicle;
                
                Log::info('Database enhancement successful', [
                    'fields_enhanced' => count($dbFields),
                    'database_id' => $dbVehicle->id
                ]);
            } else {
                Log::info('No database match found');
            }
            
        } catch (\Exception $e) {
            Log::warning('Database enhancement failed', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $extractedData;
    }

    /**
     * Check if AI enhancement is needed for missing critical specs
     */
    private function needsAiEnhancement(array $vehicle): bool
    {
        // Check if critical shipping specs are missing
        $hasDimensions = !empty($vehicle['dimensions']['length']) || 
                        !empty($vehicle['dimensions']['width']) || 
                        !empty($vehicle['dimensions']['height']);
                        
        $hasWeight = !empty($vehicle['weight']['value']);
        $hasVolume = !empty($vehicle['cargo_volume_m3']);
        $hasEngine = !empty($vehicle['engine_cc']);
        
        $needsEnhancement = !$hasDimensions || !$hasWeight || !$hasVolume;
        
        Log::info('AI enhancement check', [
            'has_dimensions' => $hasDimensions,
            'has_weight' => $hasWeight,
            'has_volume' => $hasVolume,
            'has_engine' => $hasEngine,
            'needs_enhancement' => $needsEnhancement
        ]);
        
        // Need AI if we're missing dimensions, weight, or cargo volume
        return $needsEnhancement;
    }

    /**
     * Enhance vehicle data using AI
     */
    private function enhanceFromAI(array $extractedData, array &$sources): array
    {
        $vehicle = $extractedData['vehicle'];
        
        Log::info('Starting AI enhancement for missing specs');
        
        try {
            // Build a descriptive string for the vehicle
            $vehicleDescription = trim(sprintf(
                "%s %s %s",
                $vehicle['year'] ?? '',
                $vehicle['make'] ?? '',
                $vehicle['model'] ?? ''
            ));
            
            if (!empty($vehicle['condition'])) {
                $vehicleDescription .= " ({$vehicle['condition']})";
            }
            
            // Create prompt for AI to provide missing specifications
            $prompt = $this->buildAiSpecsPrompt($vehicleDescription, $vehicle);
            
            // Call AI to get specifications
            $aiSpecs = $this->aiRouter->extract($prompt, [
                'type' => 'object',
                'properties' => [
                    'dimensions' => [
                        'type' => 'object',
                        'properties' => [
                            'length_m' => ['type' => 'number'],
                            'width_m' => ['type' => 'number'],
                            'height_m' => ['type' => 'number']
                        ]
                    ],
                    'weight_kg' => ['type' => 'number'],
                    'cargo_volume_m3' => ['type' => 'number'],
                    'engine_cc' => ['type' => 'number'],
                    'fuel_type' => ['type' => 'string'],
                    'typical_container' => ['type' => 'string'],
                    'shipping_notes' => ['type' => 'string']
                ]
            ], [
                'service' => 'openai',
                'temperature' => 0.1
            ]);
            
            // IMPORTANT: Normalize the AI response to handle various formats
            $normalizedSpecs = $this->normalizeAiResponse($aiSpecs);
            
            Log::info('AI specs normalized', [
                'original_keys' => array_keys($aiSpecs),
                'normalized_keys' => array_keys($normalizedSpecs),
                'has_dimensions' => isset($normalizedSpecs['dimensions']),
                'has_weight' => isset($normalizedSpecs['weight_kg'])
            ]);
            
            // Merge normalized AI specs into vehicle data
            $aiFields = [];
            
            // Handle dimensions - properly check and merge individual dimension values
            if (!empty($normalizedSpecs['dimensions'])) {
                // Ensure dimensions array exists in vehicle
                if (!isset($extractedData['vehicle']['dimensions'])) {
                    $extractedData['vehicle']['dimensions'] = ['unit' => 'm'];
                }
                
                // Merge individual dimension values
                if (isset($normalizedSpecs['dimensions']['length_m']) && empty($extractedData['vehicle']['dimensions']['length'])) {
                    $extractedData['vehicle']['dimensions']['length'] = $normalizedSpecs['dimensions']['length_m'];
                    $aiFields[] = 'vehicle.dimensions.length';
                }
                if (isset($normalizedSpecs['dimensions']['width_m']) && empty($extractedData['vehicle']['dimensions']['width'])) {
                    $extractedData['vehicle']['dimensions']['width'] = $normalizedSpecs['dimensions']['width_m'];
                    $aiFields[] = 'vehicle.dimensions.width';
                }
                if (isset($normalizedSpecs['dimensions']['height_m']) && empty($extractedData['vehicle']['dimensions']['height'])) {
                    $extractedData['vehicle']['dimensions']['height'] = $normalizedSpecs['dimensions']['height_m'];
                    $aiFields[] = 'vehicle.dimensions.height';
                }
            }
            
            // Handle weight - properly check the value field
            if (!empty($normalizedSpecs['weight_kg']) && (empty($extractedData['vehicle']['weight']['value']) || $extractedData['vehicle']['weight']['value'] === null)) {
                // Ensure weight array exists
                if (!isset($extractedData['vehicle']['weight'])) {
                    $extractedData['vehicle']['weight'] = ['unit' => 'kg'];
                }
                $extractedData['vehicle']['weight']['value'] = $normalizedSpecs['weight_kg'];
                $aiFields[] = 'vehicle.weight.value';
            }
            
            // Add cargo volume from AI
            if (!empty($normalizedSpecs['cargo_volume_m3']) && empty($extractedData['vehicle']['cargo_volume_m3'])) {
                $extractedData['vehicle']['cargo_volume_m3'] = $normalizedSpecs['cargo_volume_m3'];
                $aiFields[] = 'vehicle.cargo_volume_m3';
            }
            
            // Add engine specs from AI
            if (!empty($normalizedSpecs['engine_cc']) && empty($extractedData['vehicle']['engine_cc'])) {
                $extractedData['vehicle']['engine_cc'] = $normalizedSpecs['engine_cc'];
                $aiFields[] = 'vehicle.engine_cc';
            }
            
            if (!empty($normalizedSpecs['fuel_type']) && empty($extractedData['vehicle']['fuel_type'])) {
                $extractedData['vehicle']['fuel_type'] = $normalizedSpecs['fuel_type'];
                $aiFields[] = 'vehicle.fuel_type';
            }
            
            // Add shipping recommendations
            if (!empty($normalizedSpecs['typical_container'])) {
                $extractedData['vehicle']['typical_container'] = $normalizedSpecs['typical_container'];
                $aiFields[] = 'vehicle.typical_container';
            }
            
            if (!empty($normalizedSpecs['shipping_notes'])) {
                $extractedData['vehicle']['shipping_notes'] = $normalizedSpecs['shipping_notes'];
                $aiFields[] = 'vehicle.shipping_notes';
            }
            
            $sources['ai_enhanced'] = $aiFields;
            
            Log::info('AI enhancement completed', [
                'fields_enhanced' => count($aiFields),
                'enhanced_fields' => $aiFields
            ]);
            
        } catch (\Exception $e) {
            Log::warning('AI enhancement failed', [
                'error' => $e->getMessage(),
                'vehicle' => $vehicleDescription ?? 'Unknown'
            ]);
        }
        
        return $extractedData;
    }

    /**
     * Build prompt for AI specifications
     */
    private function buildAiSpecsPrompt(string $vehicleDescription, array $currentSpecs): string
    {
        $prompt = "Provide accurate technical specifications for: {$vehicleDescription}\n\n";
        
        $prompt .= "Current known specifications:\n";
        if (!empty($currentSpecs['make'])) $prompt .= "- Make: {$currentSpecs['make']}\n";
        if (!empty($currentSpecs['model'])) $prompt .= "- Model: {$currentSpecs['model']}\n";
        if (!empty($currentSpecs['year'])) $prompt .= "- Year: {$currentSpecs['year']}\n";
        if (!empty($currentSpecs['condition'])) $prompt .= "- Condition: {$currentSpecs['condition']}\n";
        
        // Add specific year context for classic cars
        if (!empty($currentSpecs['year']) && intval($currentSpecs['year']) < 1980) {
            $prompt .= "\nThis is a classic/vintage vehicle. Use historical manufacturer specifications from that era.\n";
        }
        
        $prompt .= "\nRETURN JSON IN EXACTLY THIS FORMAT:\n";
        $prompt .= "{\n";
        $prompt .= '  "dimensions": {"length_m": 4.0, "width_m": 1.6, "height_m": 1.4},' . "\n";
        $prompt .= '  "weight_kg": 950,' . "\n";
        $prompt .= '  "cargo_volume_m3": 0.5,' . "\n";
        $prompt .= '  "engine_cc": 1300,' . "\n";
        $prompt .= '  "fuel_type": "petrol",' . "\n";
        $prompt .= '  "typical_container": "20ft",' . "\n";
        $prompt .= '  "shipping_notes": "Non-runner requires flatbed"' . "\n";
        $prompt .= "}\n\n";
        
        $prompt .= "CRITICAL INSTRUCTIONS:\n";
        $prompt .= "- Use EXACT field names shown above (lowercase, with underscores)\n";
        $prompt .= "- Put numeric values directly (not in objects)\n";
        $prompt .= "- Use meters for dimensions, kg for weight\n";
        $prompt .= "- Return ONLY factual manufacturer specifications\n";
        $prompt .= "- For non-runners, include appropriate shipping notes\n";
        $prompt .= "- If a specification is unknown, omit that field entirely\n";
        
        return $prompt;
    }

    /**
     * Normalize AI response to handle various formats from OpenAI
     * 
     * OpenAI sometimes returns natural language field names instead of our schema keys
     * This method normalizes the response to match our expected structure
     */
    private function normalizeAiResponse(array $aiResponse): array
    {
        $normalized = [];
        
        Log::info('Normalizing AI response', [
            'original_keys' => array_keys($aiResponse),
            'response_sample' => json_encode(array_slice($aiResponse, 0, 3))
        ]);
        
        // Map various possible AI response keys to our expected keys
        $keyMapping = [
            // Dimensions variations
            'dimensions' => 'dimensions',
            'Dimensions' => 'dimensions',
            'vehicle dimensions' => 'dimensions',
            'Vehicle Dimensions' => 'dimensions',
            
            // Weight variations
            'weight' => 'weight_kg',
            'Weight' => 'weight_kg',
            'weight_kg' => 'weight_kg',
            'vehicle weight' => 'weight_kg',
            'Vehicle Weight' => 'weight_kg',
            
            // Cargo volume variations
            'cargo_volume_m3' => 'cargo_volume_m3',
            'cargo volume' => 'cargo_volume_m3',
            'Cargo volume' => 'cargo_volume_m3',
            'Cargo Volume' => 'cargo_volume_m3',
            'cargo_volume' => 'cargo_volume_m3',
            
            // Engine variations
            'engine_cc' => 'engine_cc',
            'engine cc' => 'engine_cc',
            'engine displacement' => 'engine_cc',
            'Engine displacement' => 'engine_cc',
            'Engine Displacement' => 'engine_cc',
            'engine_displacement' => 'engine_cc',
            
            // Fuel type variations
            'fuel_type' => 'fuel_type',
            'fuel type' => 'fuel_type',
            'Fuel type' => 'fuel_type',
            'Fuel Type' => 'fuel_type',
            
            // Container variations
            'typical_container' => 'typical_container',
            'typical container' => 'typical_container',
            'Typical container' => 'typical_container',
            'Typical shipping container' => 'typical_container',
            'typical shipping container' => 'typical_container',
            
            // Shipping notes variations
            'shipping_notes' => 'shipping_notes',
            'shipping notes' => 'shipping_notes',
            'Special shipping notes' => 'shipping_notes',
            'special shipping notes' => 'shipping_notes',
            'Special notes' => 'shipping_notes'
        ];
        
        foreach ($aiResponse as $key => $value) {
            // Try exact match first, then lowercase match
            $normalizedKey = $keyMapping[$key] ?? $keyMapping[strtolower($key)] ?? null;
            
            if (!$normalizedKey) {
                Log::warning('Unknown AI response key', [
                    'key' => $key,
                    'value_type' => gettype($value)
                ]);
                continue;
            }
            
            // Handle different value structures based on the normalized key
            switch ($normalizedKey) {
                case 'dimensions':
                    // Handle nested dimension structure
                    if (is_array($value)) {
                        $normalized['dimensions'] = [
                            'length_m' => $this->extractNumericValue($value, ['length_m', 'length', 'Length']),
                            'width_m' => $this->extractNumericValue($value, ['width_m', 'width', 'Width']),
                            'height_m' => $this->extractNumericValue($value, ['height_m', 'height', 'Height'])
                        ];
                    }
                    break;
                    
                case 'weight_kg':
                    // Extract weight value from various possible structures
                    $weightValue = $this->extractNumericValue($value, ['weight_kg', 'value', 'weight']);
                    if ($weightValue !== null) {
                        $normalized['weight_kg'] = $weightValue;
                    }
                    break;
                    
                case 'cargo_volume_m3':
                case 'engine_cc':
                    // Extract numeric values
                    $numericValue = $this->extractNumericValue($value, [$normalizedKey, 'value']);
                    if ($numericValue !== null) {
                        $normalized[$normalizedKey] = $numericValue;
                    }
                    break;
                    
                case 'fuel_type':
                case 'typical_container':
                case 'shipping_notes':
                    // Extract string values
                    $stringValue = $this->extractStringValue($value, [$normalizedKey, 'value']);
                    if ($stringValue !== null) {
                        $normalized[$normalizedKey] = $stringValue;
                    }
                    break;
            }
        }
        
        Log::info('AI response normalized', [
            'normalized_keys' => array_keys($normalized),
            'dimensions_found' => isset($normalized['dimensions']),
            'weight_found' => isset($normalized['weight_kg']),
            'engine_found' => isset($normalized['engine_cc'])
        ]);
        
        return $normalized;
    }
    
    /**
     * Extract numeric value from various possible structures
     */
    private function extractNumericValue($value, array $possibleKeys): ?float
    {
        // If value is already numeric, return it
        if (is_numeric($value)) {
            return (float) $value;
        }
        
        // If value is array, try to find numeric value with possible keys
        if (is_array($value)) {
            foreach ($possibleKeys as $key) {
                if (isset($value[$key]) && is_numeric($value[$key])) {
                    return (float) $value[$key];
                }
            }
            
            // Try to get first numeric value in array
            foreach ($value as $v) {
                if (is_numeric($v)) {
                    return (float) $v;
                }
            }
        }
        
        // Try to parse numeric from string
        if (is_string($value)) {
            $matches = [];
            if (preg_match('/(\d+(?:\.\d+)?)/', $value, $matches)) {
                return (float) $matches[1];
            }
        }
        
        return null;
    }
    
    /**
     * Extract string value from various possible structures
     */
    private function extractStringValue($value, array $possibleKeys): ?string
    {
        // If value is already string, return it
        if (is_string($value) && !empty(trim($value))) {
            return trim($value);
        }
        
        // If value is array, try to find string value with possible keys
        if (is_array($value)) {
            foreach ($possibleKeys as $key) {
                if (isset($value[$key]) && is_string($value[$key]) && !empty(trim($value[$key]))) {
                    return trim($value[$key]);
                }
            }
            
            // Try to get first non-empty string value in array
            foreach ($value as $v) {
                if (is_string($v) && !empty(trim($v))) {
                    return trim($v);
                }
            }
        }
        
        return null;
    }

    /**
     * Calculate derived fields from enhanced data
     */
    private function calculateDerivedFields(array $extractedData, array &$sources): array
    {
        $vehicle = $extractedData['vehicle'];
        $calculatedFields = [];
        
        // Calculate volume if we have dimensions
        if (!empty($vehicle['dimensions']['length']) && 
            !empty($vehicle['dimensions']['width']) && 
            !empty($vehicle['dimensions']['height'])) {
            
            $volume = $vehicle['dimensions']['length'] * 
                     $vehicle['dimensions']['width'] * 
                     $vehicle['dimensions']['height'];
            
            $extractedData['vehicle']['calculated_volume_m3'] = round($volume, 2);
            $calculatedFields[] = 'vehicle.calculated_volume_m3';
        }
        
        // Calculate shipping class based on weight
        if (!empty($vehicle['weight']['value'])) {
            $weight = $vehicle['weight']['value'];
            
            if ($weight < 1000) {
                $extractedData['vehicle']['shipping_weight_class'] = 'light';
            } elseif ($weight < 2000) {
                $extractedData['vehicle']['shipping_weight_class'] = 'medium';
            } else {
                $extractedData['vehicle']['shipping_weight_class'] = 'heavy';
            }
            
            $calculatedFields[] = 'vehicle.shipping_weight_class';
        }
        
        // Calculate typical container based on dimensions and weight
        if (!empty($extractedData['vehicle']['calculated_volume_m3']) && !empty($vehicle['weight']['value'])) {
            $volume = $extractedData['vehicle']['calculated_volume_m3'];
            $weight = $vehicle['weight']['value'];
            
            if ($volume <= 28 && $weight <= 20000) { // 20ft container limits
                $extractedData['vehicle']['recommended_container'] = '20ft_container';
            } elseif ($volume <= 58 && $weight <= 26000) { // 40ft container limits
                $extractedData['vehicle']['recommended_container'] = '40ft_container';
            } else {
                $extractedData['vehicle']['recommended_container'] = 'roro_shipping';
            }
            
            $calculatedFields[] = 'vehicle.recommended_container';
        }
        
        $sources['calculated'] = $calculatedFields;
        
        return $extractedData;
    }

    /**
     * Get summary of sources used
     */
    private function getSourcesSummary(array $sources): array
    {
        return [
            'document' => count($sources['document_extracted']),
            'database' => count($sources['database_enhanced']),
            'ai' => count($sources['ai_enhanced']),
            'calculated' => count($sources['calculated'])
        ];
    }

    /**
     * Calculate confidence score for enhancement
     */
    private function calculateEnhancementConfidence(array $sources): float
    {
        $confidence = 0.0;
        
        // Base confidence from document
        if (count($sources['document_extracted']) > 0) {
            $confidence += 0.5;
        }
        
        // Database enhancement adds high confidence
        if (count($sources['database_enhanced']) > 0) {
            $confidence += 0.3;
        }
        
        // AI enhancement adds moderate confidence
        if (count($sources['ai_enhanced']) > 0) {
            $confidence += 0.15;
        }
        
        // Calculated fields add small confidence
        if (count($sources['calculated']) > 0) {
            $confidence += 0.05;
        }
        
        return min(1.0, $confidence);
    }
}

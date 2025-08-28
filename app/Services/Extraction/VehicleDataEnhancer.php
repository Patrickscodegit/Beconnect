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
            
            // Merge AI specs into vehicle data
            $aiFields = [];
            
            // Add dimensions from AI
            if (!empty($aiSpecs['dimensions'])) {
                if (empty($extractedData['vehicle']['dimensions'])) {
                    $extractedData['vehicle']['dimensions'] = [];
                }
                
                if (!empty($aiSpecs['dimensions']['length_m']) && empty($extractedData['vehicle']['dimensions']['length'])) {
                    $extractedData['vehicle']['dimensions']['length'] = $aiSpecs['dimensions']['length_m'];
                    $extractedData['vehicle']['dimensions']['unit'] = 'm';
                    $aiFields[] = 'vehicle.dimensions.length';
                }
                if (!empty($aiSpecs['dimensions']['width_m']) && empty($extractedData['vehicle']['dimensions']['width'])) {
                    $extractedData['vehicle']['dimensions']['width'] = $aiSpecs['dimensions']['width_m'];
                    $extractedData['vehicle']['dimensions']['unit'] = 'm';
                    $aiFields[] = 'vehicle.dimensions.width';
                }
                if (!empty($aiSpecs['dimensions']['height_m']) && empty($extractedData['vehicle']['dimensions']['height'])) {
                    $extractedData['vehicle']['dimensions']['height'] = $aiSpecs['dimensions']['height_m'];
                    $extractedData['vehicle']['dimensions']['unit'] = 'm';
                    $aiFields[] = 'vehicle.dimensions.height';
                }
            }
            
            // Add weight from AI - Fixed logic to check the actual extracted data
            if (!empty($aiSpecs['weight_kg']) && (empty($extractedData['vehicle']['weight']['value']) || $extractedData['vehicle']['weight']['value'] === null)) {
                if (empty($extractedData['vehicle']['weight'])) {
                    $extractedData['vehicle']['weight'] = [];
                }
                $extractedData['vehicle']['weight']['value'] = $aiSpecs['weight_kg'];
                $extractedData['vehicle']['weight']['unit'] = 'kg';
                $aiFields[] = 'vehicle.weight.value';
            }
            
            // Add cargo volume from AI
            if (!empty($aiSpecs['cargo_volume_m3']) && empty($extractedData['vehicle']['cargo_volume_m3'])) {
                $extractedData['vehicle']['cargo_volume_m3'] = $aiSpecs['cargo_volume_m3'];
                $aiFields[] = 'vehicle.cargo_volume_m3';
            }
            
            // Add engine specs from AI
            if (!empty($aiSpecs['engine_cc']) && empty($extractedData['vehicle']['engine_cc'])) {
                $extractedData['vehicle']['engine_cc'] = $aiSpecs['engine_cc'];
                $aiFields[] = 'vehicle.engine_cc';
            }
            
            if (!empty($aiSpecs['fuel_type']) && empty($extractedData['vehicle']['fuel_type'])) {
                $extractedData['vehicle']['fuel_type'] = $aiSpecs['fuel_type'];
                $aiFields[] = 'vehicle.fuel_type';
            }
            
            // Add shipping recommendations
            if (!empty($aiSpecs['typical_container'])) {
                $extractedData['vehicle']['typical_container'] = $aiSpecs['typical_container'];
                $aiFields[] = 'vehicle.typical_container';
            }
            
            if (!empty($aiSpecs['shipping_notes'])) {
                $extractedData['vehicle']['shipping_notes'] = $aiSpecs['shipping_notes'];
                $aiFields[] = 'vehicle.shipping_notes';
            }
            
            $sources['ai_enhanced'] = $aiFields;
            
            Log::info('AI enhancement completed', [
                'fields_enhanced' => count($aiFields),
                'ai_specs_received' => array_keys($aiSpecs),
                'enhanced_fields' => $aiFields
            ]);
            
        } catch (\Exception $e) {
            Log::warning('AI enhancement failed', [
                'error' => $e->getMessage()
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
        
        // Add special instruction for classic cars
        if (isset($currentSpecs['year']) && $currentSpecs['year'] < 1980) {
            $prompt .= "\nThis is a classic/vintage vehicle. Use historical manufacturer specifications.\n";
        }
        
        $prompt .= "\nProvide the following manufacturer specifications using these exact field names:\n";
        $prompt .= "- Dimensions (use fields: length_m, width_m, height_m in meters)\n";
        $prompt .= "- Weight (use field: weight_kg in kilograms)\n";
        $prompt .= "- Cargo volume (use field: cargo_volume_m3 in cubic meters)\n";
        $prompt .= "- Engine displacement (use field: engine_cc in cubic centimeters)\n";
        $prompt .= "- Fuel type (use field: fuel_type - petrol, diesel, electric, hybrid)\n";
        $prompt .= "- Typical shipping container (use field: typical_container - 20ft, 40ft, RoRo, etc.)\n";
        $prompt .= "- Special shipping notes (use field: shipping_notes)\n\n";
        
        $prompt .= "Return only factual manufacturer specifications. Be precise and accurate.\n";
        $prompt .= "Use the exact field names specified above.\n";
        $prompt .= "For classic/vintage vehicles, provide historical manufacturer data.";
        
        return $prompt;
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

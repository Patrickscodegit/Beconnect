<?php

namespace App\Services\Extraction;

use App\Services\AiRouter;
use App\Services\VehicleDatabase\VehicleDatabaseService;
use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\Extraction\Strategies\DatabaseExtractor;
use App\Services\Extraction\Strategies\AiExtractor;
use Illuminate\Support\Facades\Log;

class HybridExtractionPipeline
{
    private array $strategies;
    
    public function __construct(
        private PatternExtractor $patternExtractor,
        private DatabaseExtractor $databaseExtractor,
        private AiExtractor $aiExtractor,
        private VehicleDatabaseService $vehicleDb
    ) {
        // Order matters - faster/cheaper strategies first
        $this->strategies = [
            'pattern' => $this->patternExtractor,
            'database' => $this->databaseExtractor,
            'ai' => $this->aiExtractor
        ];
    }
    
    /**
     * Extract data using multiple strategies and merge results intelligently
     */
    public function extract(string $content, string $documentType = 'email'): array
    {
        $startTime = microtime(true);
        
        Log::info('Starting hybrid extraction pipeline', [
            'document_type' => $documentType,
            'content_length' => strlen($content)
        ]);
        
        $results = [];
        $confidence = [];
        $strategyTimes = [];
        
        // Phase 1: Pattern-based extraction (fast, deterministic)
        $phaseStart = microtime(true);
        try {
            $results['pattern'] = $this->patternExtractor->extract($content);
            $confidence['pattern'] = $this->calculateConfidence($results['pattern']);
            $strategyTimes['pattern'] = microtime(true) - $phaseStart;
            
            Log::info('Pattern extraction completed', [
                'confidence' => $confidence['pattern'],
                'time_ms' => round($strategyTimes['pattern'] * 1000, 2),
                'vehicle_found' => !empty($results['pattern']['vehicle']['brand'])
            ]);
        } catch (\Exception $e) {
            Log::error('Pattern extraction failed', ['error' => $e->getMessage()]);
            $results['pattern'] = $this->getEmptyResult();
            $confidence['pattern'] = 0;
        }
        
        // Phase 2: Database enhancement (if we have initial vehicle data)
        if (!empty($results['pattern']['vehicle'])) {
            $phaseStart = microtime(true);
            try {
                $results['database'] = $this->databaseExtractor->enhance(
                    $results['pattern'], 
                    $content
                );
                $confidence['database'] = $this->calculateConfidence($results['database']);
                $strategyTimes['database'] = microtime(true) - $phaseStart;
                
                Log::info('Database enhancement completed', [
                    'confidence' => $confidence['database'],
                    'time_ms' => round($strategyTimes['database'] * 1000, 2),
                    'database_match' => !empty($results['database']['vehicle']['database_match'])
                ]);
            } catch (\Exception $e) {
                Log::error('Database enhancement failed', ['error' => $e->getMessage()]);
                $results['database'] = $results['pattern'];
                $confidence['database'] = $confidence['pattern'];
            }
        }
        
        // Phase 3: AI extraction (expensive, but comprehensive)
        // Only if we need more data or low confidence
        if ($this->needsAiExtraction($results, $confidence)) {
            $phaseStart = microtime(true);
            try {
                $aiPrompt = $this->buildSmartPrompt($content, $results);
                $schema = $this->getEnhancedSchema();
                
                $results['ai'] = $this->aiExtractor->extract($aiPrompt, $schema);
                $confidence['ai'] = $this->calculateConfidence($results['ai']);
                $strategyTimes['ai'] = microtime(true) - $phaseStart;
                
                Log::info('AI extraction completed', [
                    'confidence' => $confidence['ai'],
                    'time_ms' => round($strategyTimes['ai'] * 1000, 2),
                    'prompt_length' => strlen($aiPrompt)
                ]);
            } catch (\Exception $e) {
                Log::error('AI extraction failed', ['error' => $e->getMessage()]);
                $results['ai'] = $this->getEmptyResult();
                $confidence['ai'] = 0;
            }
        } else {
            Log::info('AI extraction skipped', [
                'reason' => 'sufficient_confidence',
                'max_confidence' => max($confidence)
            ]);
        }
        
        // Phase 4: Merge and validate
        $merged = $this->mergeResults($results, $confidence);
        
        // Phase 5: Final database validation and enrichment
        $validated = $this->finalValidation($merged);
        
        $totalTime = microtime(true) - $startTime;
        
        Log::info('Hybrid extraction pipeline completed', [
            'total_time_ms' => round($totalTime * 1000, 2),
            'strategies_used' => array_keys($results),
            'final_confidence' => $this->calculateOverallConfidence($confidence)
        ]);
        
        return [
            'data' => $validated,
            'metadata' => [
                'extraction_strategies' => array_keys($results),
                'confidence_scores' => $confidence,
                'overall_confidence' => $this->calculateOverallConfidence($confidence),
                'database_validated' => !empty($validated['vehicle']['database_match']),
                'processing_time_ms' => round($totalTime * 1000, 2),
                'strategy_times' => array_map(fn($time) => round($time * 1000, 2), $strategyTimes),
                'extraction_pipeline_version' => '1.0'
            ]
        ];
    }
    
    /**
     * Determine if AI extraction is needed
     */
    private function needsAiExtraction(array $results, array $confidence): bool
    {
        // Always use AI if confidence is low
        $maxConfidence = max($confidence);
        if ($maxConfidence < 0.7) {
            Log::info('AI needed: low confidence', ['max_confidence' => $maxConfidence]);
            return true;
        }
        
        // Check if critical fields are missing
        $criticalFields = [
            'vehicle.brand',
            'vehicle.model', 
            'shipment.origin',
            'shipment.destination',
            'contact.email'
        ];
        
        $missingCritical = [];
        foreach ($criticalFields as $field) {
            $found = false;
            foreach ($results as $strategyName => $data) {
                if (!empty(data_get($data, $field))) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missingCritical[] = $field;
            }
        }
        
        if (!empty($missingCritical)) {
            Log::info('AI needed: missing critical fields', ['missing' => $missingCritical]);
            return true;
        }
        
        // Check if we have incomplete vehicle data
        if (!empty($results['pattern']['vehicle']) || !empty($results['database']['vehicle'])) {
            $vehicleData = $results['database']['vehicle'] ?? $results['pattern']['vehicle'];
            $importantVehicleFields = ['year', 'vin', 'engine_cc', 'dimensions'];
            
            $missingVehicleFields = 0;
            foreach ($importantVehicleFields as $field) {
                if (empty($vehicleData[$field])) {
                    $missingVehicleFields++;
                }
            }
            
            if ($missingVehicleFields >= 2) {
                Log::info('AI needed: incomplete vehicle data', [
                    'missing_fields' => $missingVehicleFields
                ]);
                return true;
            }
        }
        
        Log::info('AI not needed: sufficient data extracted');
        return false;
    }
    
    /**
     * Build a smart prompt that includes what we've already found
     */
    private function buildSmartPrompt(string $content, array $existingResults): string
    {
        $prompt = "Extract shipping and vehicle information from this email. Focus on missing or incomplete data.\n\n";
        
        // Add context about what we already know
        $bestResult = $this->getBestExistingResult($existingResults);
        
        if (!empty($bestResult['vehicle'])) {
            $vehicle = $bestResult['vehicle'];
            $prompt .= "Context: This appears to be about a ";
            if (!empty($vehicle['year'])) $prompt .= $vehicle['year'] . " ";
            if (!empty($vehicle['brand'])) $prompt .= $vehicle['brand'] . " ";
            if (!empty($vehicle['model'])) $prompt .= $vehicle['model'];
            $prompt .= "\n\n";
            
            // Specify what we still need
            $needed = [];
            if (empty($vehicle['vin'])) $needed[] = "VIN number";
            if (empty($vehicle['year'])) $needed[] = "manufacturing year";
            if (empty($vehicle['engine_cc'])) $needed[] = "engine displacement (CC)";
            if (empty($vehicle['fuel_type'])) $needed[] = "fuel type";
            if (empty($vehicle['dimensions'])) $needed[] = "dimensions (L×W×H)";
            if (empty($vehicle['weight_kg'])) $needed[] = "weight";
            if (empty($vehicle['condition'])) $needed[] = "condition (new/used/damaged)";
            if (empty($vehicle['color'])) $needed[] = "color";
            
            if (!empty($needed)) {
                $prompt .= "Still needed for vehicle: " . implode(", ", $needed) . "\n\n";
            }
        }
        
        if (!empty($bestResult['shipment'])) {
            $shipment = $bestResult['shipment'];
            $needed = [];
            if (empty($shipment['origin'])) $needed[] = "origin location";
            if (empty($shipment['destination'])) $needed[] = "destination location";
            if (empty($shipment['shipping_type'])) $needed[] = "shipping method (RORO/Container)";
            
            if (!empty($needed)) {
                $prompt .= "Still needed for shipment: " . implode(", ", $needed) . "\n\n";
            }
        }
        
        // Add specific extraction instructions
        $prompt .= "Extract the following with high precision:\n";
        $prompt .= "- Complete vehicle specifications (year, make, model, VIN, engine CC, fuel type, dimensions L×W×H, weight, color, condition)\n";
        $prompt .= "- Shipping route (origin city/country → destination city/country, ports if mentioned)\n";
        $prompt .= "- Shipping method (RORO, 20ft container, 40ft container, 40HC container)\n";
        $prompt .= "- Timeline (pickup date, delivery date, ETD, ETA)\n";
        $prompt .= "- Pricing (amount, currency, incoterm like FOB/CIF)\n";
        $prompt .= "- Contact details (name, company, email, phone)\n\n";
        
        $prompt .= "Return ONLY the missing or more detailed information. Be precise with technical specifications.\n\n";
        $prompt .= "Email content:\n" . $content;
        
        return $prompt;
    }
    
    /**
     * Get the best existing result for context
     */
    private function getBestExistingResult(array $results): array
    {
        // Prefer database enhanced, then pattern, then empty
        if (!empty($results['database'])) {
            return $results['database'];
        }
        
        if (!empty($results['pattern'])) {
            return $results['pattern'];
        }
        
        return [];
    }
    
    /**
     * Enhanced schema for vehicle extraction
     */
    private function getEnhancedSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'vehicle' => [
                    'type' => 'object',
                    'properties' => [
                        'vin' => [
                            'type' => 'string', 
                            'pattern' => '^[A-HJ-NPR-Z0-9]{17}$',
                            'description' => 'Vehicle Identification Number - exactly 17 characters'
                        ],
                        'year' => [
                            'type' => 'integer', 
                            'minimum' => 1900, 
                            'maximum' => 2030,
                            'description' => 'Manufacturing year'
                        ],
                        'brand' => [
                            'type' => 'string',
                            'description' => 'Vehicle manufacturer/brand (e.g., BMW, Mercedes, Toyota)'
                        ],
                        'model' => [
                            'type' => 'string',
                            'description' => 'Vehicle model name (e.g., X5, E-Class, Camry)'
                        ],
                        'variant' => [
                            'type' => 'string',
                            'description' => 'Specific variant or trim level'
                        ],
                        'engine_cc' => [
                            'type' => 'integer',
                            'minimum' => 500,
                            'maximum' => 8000,
                            'description' => 'Engine displacement in cubic centimeters'
                        ],
                        'fuel_type' => [
                            'type' => 'string', 
                            'enum' => ['petrol', 'diesel', 'electric', 'hybrid', 'lpg', 'cng'],
                            'description' => 'Type of fuel the vehicle uses'
                        ],
                        'transmission' => [
                            'type' => 'string', 
                            'enum' => ['manual', 'automatic', 'cvt'],
                            'description' => 'Transmission type'
                        ],
                        'color' => [
                            'type' => 'string',
                            'description' => 'Vehicle exterior color'
                        ],
                        'condition' => [
                            'type' => 'string', 
                            'enum' => ['new', 'used', 'damaged'],
                            'description' => 'Vehicle condition'
                        ],
                        'mileage_km' => [
                            'type' => 'integer',
                            'minimum' => 0,
                            'description' => 'Odometer reading in kilometers'
                        ],
                        'weight_kg' => [
                            'type' => 'number',
                            'minimum' => 500,
                            'maximum' => 10000,
                            'description' => 'Vehicle weight in kilograms'
                        ],
                        'dimensions' => [
                            'type' => 'object',
                            'properties' => [
                                'length_m' => [
                                    'type' => 'number',
                                    'minimum' => 2,
                                    'maximum' => 15,
                                    'description' => 'Length in meters'
                                ],
                                'width_m' => [
                                    'type' => 'number',
                                    'minimum' => 1,
                                    'maximum' => 3,
                                    'description' => 'Width in meters'
                                ],
                                'height_m' => [
                                    'type' => 'number',
                                    'minimum' => 1,
                                    'maximum' => 4,
                                    'description' => 'Height in meters'
                                ]
                            ]
                        ]
                    ]
                ],
                'shipment' => [
                    'type' => 'object',
                    'properties' => [
                        'origin' => [
                            'type' => 'string',
                            'description' => 'Origin city and country'
                        ],
                        'destination' => [
                            'type' => 'string',
                            'description' => 'Destination city and country'
                        ],
                        'origin_port' => [
                            'type' => 'string',
                            'description' => 'Port of loading (POL)'
                        ],
                        'destination_port' => [
                            'type' => 'string',
                            'description' => 'Port of discharge (POD)'
                        ],
                        'shipping_type' => [
                            'type' => 'string', 
                            'enum' => ['roro', 'container', 'air'],
                            'description' => 'Method of shipping'
                        ],
                        'container_size' => [
                            'type' => 'string', 
                            'enum' => ['20ft', '40ft', '40hc'],
                            'description' => 'Container size if applicable'
                        ]
                    ]
                ],
                'dates' => [
                    'type' => 'object',
                    'properties' => [
                        'pickup_date' => [
                            'type' => 'string', 
                            'format' => 'date',
                            'description' => 'Vehicle pickup/collection date'
                        ],
                        'delivery_date' => [
                            'type' => 'string', 
                            'format' => 'date',
                            'description' => 'Expected delivery date'
                        ],
                        'etd' => [
                            'type' => 'string', 
                            'format' => 'date',
                            'description' => 'Estimated time of departure from port'
                        ],
                        'eta' => [
                            'type' => 'string', 
                            'format' => 'date',
                            'description' => 'Estimated time of arrival at port'
                        ]
                    ]
                ],
                'pricing' => [
                    'type' => 'object',
                    'properties' => [
                        'amount' => [
                            'type' => 'number',
                            'minimum' => 0,
                            'description' => 'Price amount'
                        ],
                        'currency' => [
                            'type' => 'string', 
                            'pattern' => '^[A-Z]{3}$',
                            'description' => 'Currency code (USD, EUR, GBP)'
                        ],
                        'incoterm' => [
                            'type' => 'string', 
                            'enum' => ['FOB', 'CIF', 'CFR', 'EXW', 'DDP', 'DAP'],
                            'description' => 'International commercial terms'
                        ]
                    ]
                ],
                'contact' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'Contact person name'
                        ],
                        'company' => [
                            'type' => 'string',
                            'description' => 'Company name'
                        ],
                        'email' => [
                            'type' => 'string', 
                            'format' => 'email',
                            'description' => 'Email address'
                        ],
                        'phone' => [
                            'type' => 'string',
                            'description' => 'Phone number'
                        ]
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Merge results from different strategies intelligently
     */
    private function mergeResults(array $results, array $confidence): array
    {
        $merged = [];
        
        // Start with the highest confidence result as base
        $strategies = array_keys($results);
        usort($strategies, fn($a, $b) => ($confidence[$b] ?? 0) <=> ($confidence[$a] ?? 0));
        
        foreach ($strategies as $strategy) {
            $data = $results[$strategy] ?? [];
            $weight = $confidence[$strategy] ?? 0;
            
            foreach ($data as $section => $sectionData) {
                if ($section === 'metadata') continue;
                
                if (!isset($merged[$section])) {
                    $merged[$section] = $sectionData;
                    $merged['_sources'][$section] = $strategy;
                } else {
                    // Merge section data intelligently
                    $merged[$section] = $this->mergeSectionData(
                        $merged[$section], 
                        $sectionData, 
                        $strategy,
                        $weight
                    );
                }
            }
        }
        
        // Clean up sources metadata
        unset($merged['_sources']);
        
        return $merged;
    }
    
    /**
     * Merge data for a specific section
     */
    private function mergeSectionData(array $existing, array $new, string $strategy, float $confidence): array
    {
        foreach ($new as $key => $value) {
            if (!isset($existing[$key]) || empty($existing[$key])) {
                // Add new field
                $existing[$key] = $value;
            } elseif (!empty($value)) {
                // Decide whether to override based on strategy and data quality
                if ($this->shouldOverride($existing[$key], $value, $strategy, $confidence)) {
                    $existing[$key] = $value;
                }
            }
        }
        
        return $existing;
    }
    
    /**
     * Determine if we should override existing value with new value
     */
    private function shouldOverride($existing, $new, string $strategy, float $confidence): bool
    {
        // Database data is usually more authoritative
        if ($strategy === 'database' && $confidence > 0.7) {
            return true;
        }
        
        // AI data overrides if much more detailed
        if ($strategy === 'ai' && $confidence > 0.8) {
            if (is_array($new) && is_array($existing)) {
                return count(array_filter($new)) > count(array_filter($existing));
            }
            if (is_string($new) && is_string($existing)) {
                return strlen($new) > strlen($existing) * 1.5;
            }
        }
        
        return false;
    }
    
    /**
     * Final validation and database enrichment
     */
    private function finalValidation(array $data): array
    {
        // Final vehicle enrichment if we have enough data
        if (!empty($data['vehicle']) && empty($data['vehicle']['database_match'])) {
            $enriched = $this->vehicleDb->enrichVehicleData($data['vehicle']);
            if (!empty($enriched['database_match'])) {
                $data['vehicle'] = $enriched;
                Log::info('Final vehicle enrichment successful', [
                    'database_id' => $enriched['database_id']
                ]);
            }
        }
        
        // Validate data consistency
        $validation = $this->performFinalValidation($data);
        $data['final_validation'] = $validation;
        
        return $data;
    }
    
    /**
     * Perform final data validation
     */
    private function performFinalValidation(array $data): array
    {
        $validation = [
            'valid' => true,
            'warnings' => [],
            'quality_score' => 1.0
        ];
        
        // Validate vehicle data if present
        if (!empty($data['vehicle'])) {
            $vehicleValidation = $this->vehicleDb->validateVehicleData($data['vehicle']);
            if (!$vehicleValidation['valid']) {
                $validation['quality_score'] *= 0.8;
                $validation['warnings'] = array_merge(
                    $validation['warnings'],
                    $vehicleValidation['warnings']
                );
            }
        }
        
        // Check data completeness
        $completeness = $this->calculateDataCompleteness($data);
        $validation['completeness_score'] = $completeness;
        
        if ($completeness < 0.5) {
            $validation['warnings'][] = "Low data completeness: {$completeness}";
            $validation['quality_score'] *= 0.9;
        }
        
        return $validation;
    }
    
    /**
     * Calculate data completeness score
     */
    private function calculateDataCompleteness(array $data): float
    {
        $totalFields = 0;
        $filledFields = 0;
        
        $sections = [
            'vehicle' => ['brand', 'model', 'year', 'vin', 'engine_cc', 'fuel_type', 'dimensions', 'weight_kg'],
            'contact' => ['email', 'name', 'phone', 'company'],
            'shipment' => ['origin', 'destination', 'shipping_type'],
            'pricing' => ['amount', 'currency'],
            'dates' => ['pickup_date', 'delivery_date']
        ];
        
        foreach ($sections as $section => $fields) {
            foreach ($fields as $field) {
                $totalFields++;
                if (!empty(data_get($data, "$section.$field"))) {
                    $filledFields++;
                }
            }
        }
        
        return $totalFields > 0 ? round($filledFields / $totalFields, 2) : 0;
    }
    
    /**
     * Calculate confidence for a result set
     */
    private function calculateConfidence(array $data): float
    {
        $score = 0;
        $maxScore = 0;
        
        // Vehicle confidence (most important)
        $vehicleWeights = [
            'brand' => 15, 'model' => 15, 'year' => 10, 'vin' => 20,
            'engine_cc' => 5, 'fuel_type' => 5, 'dimensions' => 10, 'weight_kg' => 5
        ];
        
        foreach ($vehicleWeights as $field => $weight) {
            $maxScore += $weight;
            if (!empty(data_get($data, "vehicle.$field"))) {
                $score += $weight;
            }
        }
        
        // Contact confidence
        $contactWeights = ['email' => 10, 'phone' => 5, 'name' => 3, 'company' => 2];
        foreach ($contactWeights as $field => $weight) {
            $maxScore += $weight;
            if (!empty(data_get($data, "contact.$field"))) {
                $score += $weight;
            }
        }
        
        // Shipment confidence
        $shipmentWeights = ['origin' => 8, 'destination' => 8, 'shipping_type' => 4];
        foreach ($shipmentWeights as $field => $weight) {
            $maxScore += $weight;
            if (!empty(data_get($data, "shipment.$field"))) {
                $score += $weight;
            }
        }
        
        return $maxScore > 0 ? round($score / $maxScore, 2) : 0;
    }
    
    /**
     * Calculate overall confidence from individual strategy confidences
     */
    private function calculateOverallConfidence(array $confidences): float
    {
        if (empty($confidences)) return 0;
        
        // Weighted average with preference for database validation
        $weights = [
            'pattern' => 0.3,
            'database' => 0.5,
            'ai' => 0.2
        ];
        
        $totalWeight = 0;
        $weightedSum = 0;
        
        foreach ($confidences as $strategy => $confidence) {
            $weight = $weights[$strategy] ?? 0.1;
            $weightedSum += $confidence * $weight;
            $totalWeight += $weight;
        }
        
        return $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : 0;
    }
    
    /**
     * Get empty result structure
     */
    private function getEmptyResult(): array
    {
        return [
            'vehicle' => [],
            'shipment' => [],
            'contact' => [],
            'dates' => [],
            'pricing' => [],
            'metadata' => []
        ];
    }
}

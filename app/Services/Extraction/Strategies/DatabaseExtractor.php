<?php

namespace App\Services\Extraction\Strategies;

use App\Services\VehicleDatabase\VehicleDatabaseService;
use Illuminate\Support\Facades\Log;

class DatabaseExtractor
{
    public function __construct(
        private VehicleDatabaseService $vehicleDb
    ) {}
    
    /**
     * Enhance extraction results with database data
     */
    public function enhance(array $extractedData, string $originalContent): array
    {
        $enhanced = $extractedData;
        
        // Enhance vehicle data with database information
        if (!empty($extractedData['vehicle'])) {
            $enhanced['vehicle'] = $this->enhanceVehicleWithDatabase(
                $extractedData['vehicle'], 
                $originalContent
            );
        }
        
        // Add database validation
        $enhanced['database_validation'] = $this->validateExtractedData($enhanced);
        
        // Add confidence scoring based on database matches
        $enhanced['metadata']['database_confidence'] = $this->calculateDatabaseConfidence($enhanced);
        $enhanced['metadata']['enhancement_method'] = 'database_enrichment';
        
        Log::info('Database enhancement completed', [
            'vehicle_database_match' => !empty($enhanced['vehicle']['database_match']),
            'validation_score' => $enhanced['database_validation']['score'] ?? 0,
            'confidence' => $enhanced['metadata']['database_confidence'] ?? 0
        ]);
        
        return $enhanced;
    }
    
    /**
     * Enhance vehicle data using database lookup
     */
    private function enhanceVehicleWithDatabase(array $vehicleData, string $content): array
    {
        // First, try to find exact or fuzzy match in database
        $dbVehicle = $this->vehicleDb->findVehicle($vehicleData);
        
        if ($dbVehicle) {
            // Merge database data with extracted data (extracted takes priority for specific values)
            $enhanced = $this->mergeVehicleData($vehicleData, $dbVehicle->toArray());
            
            $enhanced['database_match'] = true;
            $enhanced['database_id'] = $dbVehicle->id;
            $enhanced['database_confidence'] = $this->calculateVehicleMatchConfidence($vehicleData, $dbVehicle);
            
            Log::info('Vehicle found in database', [
                'database_id' => $dbVehicle->id,
                'matched_vehicle' => $dbVehicle->make . ' ' . $dbVehicle->model,
                'confidence' => $enhanced['database_confidence']
            ]);
        } else {
            // No exact match, try partial enhancement
            $enhanced = $this->partialDatabaseEnhancement($vehicleData, $content);
        }
        
        // Enhance VIN data if available
        if (!empty($vehicleData['vin'])) {
            $vinData = $this->vehicleDb->decodeVIN($vehicleData['vin']);
            if ($vinData) {
                $enhanced['vin_decoded'] = $vinData;
                
                // Cross-validate VIN data with extracted data
                if (!empty($vinData['year']) && empty($enhanced['year'])) {
                    $enhanced['year'] = $vinData['year'];
                }
                
                // Add manufacturer validation
                if (!empty($vinData['manufacturer']) && !empty($enhanced['brand'])) {
                    $enhanced['manufacturer_match'] = $this->validateManufacturer(
                        $enhanced['brand'], 
                        $vinData['manufacturer']
                    );
                }
            }
        }
        
        // Validate extracted data against database rules
        $validation = $this->vehicleDb->validateVehicleData($enhanced);
        $enhanced['validation'] = $validation;
        
        return $enhanced;
    }
    
    /**
     * Merge vehicle data intelligently (extracted data takes priority for specific values)
     */
    private function mergeVehicleData(array $extracted, array $database): array
    {
        // Start with database data as base
        $merged = [
            'make' => $database['make'],
            'model' => $database['model'],
            'variant' => $database['variant'] ?? null,
            'year' => $database['year'],
            'length_m' => $database['length_m'],
            'width_m' => $database['width_m'], 
            'height_m' => $database['height_m'],
            'wheelbase_m' => $database['wheelbase_m'] ?? null,
            'weight_kg' => $database['weight_kg'],
            'engine_cc' => $database['engine_cc'],
            'fuel_type' => $database['fuel_type'],
        ];
        
        // Override with extracted data where available and more specific
        foreach ($extracted as $key => $value) {
            if (!empty($value)) {
                // Special handling for dimensions
                if ($key === 'dimensions' && is_array($value)) {
                    $merged['length_m'] = $value['length_m'] ?? $merged['length_m'];
                    $merged['width_m'] = $value['width_m'] ?? $merged['width_m'];
                    $merged['height_m'] = $value['height_m'] ?? $merged['height_m'];
                } else {
                    $merged[$key] = $value;
                }
            }
        }
        
        // Reconstruct dimensions array if we have the components
        if (!empty($merged['length_m']) || !empty($merged['width_m']) || !empty($merged['height_m'])) {
            $merged['dimensions'] = [
                'length_m' => $merged['length_m'] ?? null,
                'width_m' => $merged['width_m'] ?? null,
                'height_m' => $merged['height_m'] ?? null
            ];
            
            // Calculate volume if all dimensions available
            if ($merged['dimensions']['length_m'] && $merged['dimensions']['width_m'] && $merged['dimensions']['height_m']) {
                $merged['dimensions']['volume_m3'] = round(
                    $merged['dimensions']['length_m'] * 
                    $merged['dimensions']['width_m'] * 
                    $merged['dimensions']['height_m'], 
                    2
                );
            }
        }
        
        return $merged;
    }
    
    /**
     * Partial enhancement when no exact vehicle match found
     */
    private function partialDatabaseEnhancement(array $vehicleData, string $content): array
    {
        $enhanced = $vehicleData;
        $enhanced['database_match'] = false;
        
        // Try to validate/enhance brand if available
        if (!empty($vehicleData['brand'])) {
            $brands = $this->vehicleDb->getAllBrands();
            
            // Find closest brand match
            $closestBrand = $this->findClosestBrand($vehicleData['brand'], $brands->toArray());
            if ($closestBrand && $closestBrand !== $vehicleData['brand']) {
                $enhanced['suggested_brand'] = $closestBrand;
                $enhanced['brand_correction'] = true;
                
                // Try to find models for corrected brand
                $models = $this->vehicleDb->getModelsForBrand($closestBrand);
                if (!empty($vehicleData['model'])) {
                    $closestModel = $this->findClosestModel($vehicleData['model'], $models->toArray());
                    if ($closestModel) {
                        $enhanced['suggested_model'] = $closestModel;
                    }
                }
            }
        }
        
        // Extract additional patterns specific to known database vehicles
        $enhanced = $this->extractDatabaseSpecificPatterns($enhanced, $content);
        
        return $enhanced;
    }
    
    /**
     * Extract patterns specific to vehicles in our database
     */
    private function extractDatabaseSpecificPatterns(array $vehicleData, string $content): array
    {
        $patterns = $this->vehicleDb->getBrandModelPatterns();
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern['regex'], $content)) {
                // We found a pattern that matches our database
                $vehicleData['database_pattern_match'] = $pattern['pattern'];
                $vehicleData['suggested_brand'] = $pattern['brand'];
                $vehicleData['suggested_model'] = $pattern['model'];
                break;
            }
        }
        
        return $vehicleData;
    }
    
    /**
     * Calculate confidence score for vehicle database match
     */
    private function calculateVehicleMatchConfidence(array $extracted, $dbVehicle): float
    {
        $score = 0;
        $weights = [
            'brand' => 0.3,
            'model' => 0.3,
            'year' => 0.2,
            'engine_cc' => 0.1,
            'fuel_type' => 0.1
        ];
        
        foreach ($weights as $field => $weight) {
            $extractedValue = $extracted[$field] ?? null;
            $dbValue = $dbVehicle->$field ?? null;
            
            if ($extractedValue && $dbValue) {
                if (is_string($extractedValue) && is_string($dbValue)) {
                    // String similarity
                    $similarity = $this->calculateStringSimilarity($extractedValue, $dbValue);
                    $score += $weight * $similarity;
                } elseif (is_numeric($extractedValue) && is_numeric($dbValue)) {
                    // Numeric similarity (allow 10% variance)
                    $variance = abs($extractedValue - $dbValue) / max($extractedValue, $dbValue);
                    $score += $weight * max(0, 1 - $variance * 10);
                } elseif ($extractedValue === $dbValue) {
                    $score += $weight;
                }
            }
        }
        
        return round($score, 2);
    }
    
    /**
     * Validate extracted data against database
     */
    private function validateExtractedData(array $data): array
    {
        $validation = [
            'score' => 1.0,
            'warnings' => [],
            'errors' => [],
            'suggestions' => []
        ];
        
        if (!empty($data['vehicle'])) {
            $vehicleValidation = $this->vehicleDb->validateVehicleData($data['vehicle']);
            
            if (!$vehicleValidation['valid']) {
                $validation['score'] *= 0.7;
                $validation['warnings'] = array_merge(
                    $validation['warnings'], 
                    $vehicleValidation['warnings']
                );
            }
        }
        
        // Validate data consistency
        $consistencyCheck = $this->validateDataConsistency($data);
        $validation['score'] *= $consistencyCheck['score'];
        $validation['warnings'] = array_merge($validation['warnings'], $consistencyCheck['warnings']);
        
        return $validation;
    }
    
    /**
     * Validate data consistency across fields
     */
    private function validateDataConsistency(array $data): array
    {
        $score = 1.0;
        $warnings = [];
        
        // Check VIN vs extracted data consistency
        if (!empty($data['vehicle']['vin']) && !empty($data['vehicle']['vin_decoded'])) {
            $vinData = $data['vehicle']['vin_decoded'];
            
            // Check year consistency
            if (!empty($data['vehicle']['year']) && !empty($vinData['year'])) {
                if (abs($data['vehicle']['year'] - $vinData['year']) > 1) {
                    $warnings[] = "Year mismatch: extracted {$data['vehicle']['year']} vs VIN {$vinData['year']}";
                    $score *= 0.8;
                }
            }
            
            // Check manufacturer consistency
            if (!empty($data['vehicle']['brand']) && !empty($vinData['manufacturer'])) {
                if (!$this->validateManufacturer($data['vehicle']['brand'], $vinData['manufacturer'])) {
                    $warnings[] = "Brand/manufacturer mismatch: {$data['vehicle']['brand']} vs {$vinData['manufacturer']}";
                    $score *= 0.9;
                }
            }
        }
        
        return [
            'score' => $score,
            'warnings' => $warnings
        ];
    }
    
    /**
     * Calculate database confidence based on matches and validation
     */
    private function calculateDatabaseConfidence(array $data): float
    {
        $confidence = 0.5; // Base confidence
        
        // Vehicle database match
        if (!empty($data['vehicle']['database_match'])) {
            $confidence += 0.3;
            
            if (!empty($data['vehicle']['database_confidence'])) {
                $confidence += $data['vehicle']['database_confidence'] * 0.2;
            }
        }
        
        // VIN validation
        if (!empty($data['vehicle']['vin_decoded'])) {
            $confidence += 0.2;
        }
        
        // Validation score
        if (!empty($data['database_validation']['score'])) {
            $confidence *= $data['database_validation']['score'];
        }
        
        return round(min(1.0, $confidence), 2);
    }
    
    /**
     * Helper methods
     */
    private function findClosestBrand(string $brand, array $brands): ?string
    {
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($brands as $dbBrand) {
            $score = $this->calculateStringSimilarity($brand, $dbBrand);
            if ($score > $bestScore && $score > 0.6) {
                $bestScore = $score;
                $bestMatch = $dbBrand;
            }
        }
        
        return $bestMatch;
    }
    
    private function findClosestModel(string $model, array $models): ?string
    {
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($models as $dbModel) {
            $score = $this->calculateStringSimilarity($model, $dbModel);
            if ($score > $bestScore && $score > 0.6) {
                $bestScore = $score;
                $bestMatch = $dbModel;
            }
        }
        
        return $bestMatch;
    }
    
    private function calculateStringSimilarity(string $str1, string $str2): float
    {
        // Use multiple similarity algorithms for best results
        $levenshtein = 1 - (levenshtein(strtolower($str1), strtolower($str2)) / max(strlen($str1), strlen($str2)));
        $soundex = soundex($str1) === soundex($str2) ? 1 : 0;
        $metaphone = metaphone($str1) === metaphone($str2) ? 1 : 0;
        
        // Weighted average
        return ($levenshtein * 0.6) + ($soundex * 0.2) + ($metaphone * 0.2);
    }
    
    private function validateManufacturer(string $brand, string $manufacturer): bool
    {
        // Common brand-manufacturer mappings
        $mappings = [
            'bmw' => ['bmw ag', 'bmw', 'bayerische motoren werke'],
            'mercedes' => ['mercedes-benz', 'mercedes-benz ag', 'daimler'],
            'audi' => ['audi ag', 'audi', 'volkswagen group'],
            'volkswagen' => ['volkswagen ag', 'vw', 'volkswagen'],
            'toyota' => ['toyota motor', 'toyota motor corporation'],
            'nissan' => ['nissan motor', 'nissan motor company'],
            'ford' => ['ford motor company', 'ford-werke gmbh'],
            'chevrolet' => ['general motors', 'gm'],
        ];
        
        $brand = strtolower($brand);
        $manufacturer = strtolower($manufacturer);
        
        // Direct match
        if (strpos($manufacturer, $brand) !== false || strpos($brand, $manufacturer) !== false) {
            return true;
        }
        
        // Check mappings
        if (isset($mappings[$brand])) {
            foreach ($mappings[$brand] as $validManufacturer) {
                if (strpos($manufacturer, $validManufacturer) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }
}

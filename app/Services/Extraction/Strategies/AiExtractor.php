<?php

namespace App\Services\Extraction\Strategies;

use App\Services\AiRouter;
use Illuminate\Support\Facades\Log;

class AiExtractor
{
    public function __construct(
        private AiRouter $aiRouter
    ) {}
    
    /**
     * Extract data using AI with enhanced prompting
     */
    public function extract(string $prompt, array $schema = [], array $options = []): array
    {
        $defaultOptions = [
            'service' => config('ai.primary_service', 'openai'),
            'cheap' => false, // Use best model for comprehensive extraction
            'temperature' => 0.1 // Low temperature for consistent results
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        try {
            Log::info('Starting AI extraction', [
                'service' => $options['service'],
                'schema_fields' => count($schema['properties'] ?? []),
                'prompt_length' => strlen($prompt)
            ]);
            
            $result = $this->aiRouter->extract($prompt, $schema, $options);
            
            // Post-process AI results
            $processed = $this->postProcessAiResults($result);
            
            // Add AI-specific metadata
            $processed['metadata'] = array_merge($processed['metadata'] ?? [], [
                'extraction_method' => 'ai_enhanced',
                'ai_service' => $options['service'],
                'ai_confidence' => $this->calculateAiConfidence($processed)
            ]);
            
            Log::info('AI extraction completed', [
                'vehicle_found' => !empty($processed['vehicle']),
                'contact_found' => !empty($processed['contact']),
                'confidence' => $processed['metadata']['ai_confidence']
            ]);
            
            return $processed;
            
        } catch (\Exception $e) {
            Log::error('AI extraction failed', [
                'error' => $e->getMessage(),
                'service' => $options['service']
            ]);
            
            // Return empty structure on failure
            return [
                'vehicle' => [],
                'shipment' => [],
                'contact' => [],
                'dates' => [],
                'pricing' => [],
                'metadata' => [
                    'extraction_method' => 'ai_failed',
                    'error' => $e->getMessage()
                ]
            ];
        }
    }
    
    /**
     * Post-process AI results to standardize and validate
     */
    private function postProcessAiResults(array $result): array
    {
        $processed = $result;
        
        // Standardize vehicle data
        if (!empty($processed['vehicle'])) {
            $processed['vehicle'] = $this->standardizeVehicleData($processed['vehicle']);
        }
        
        // Standardize contact data
        if (!empty($processed['contact'])) {
            $processed['contact'] = $this->standardizeContactData($processed['contact']);
        }
        
        // Standardize pricing data
        if (!empty($processed['pricing'])) {
            $processed['pricing'] = $this->standardizePricingData($processed['pricing']);
        }
        
        // Standardize dates
        if (!empty($processed['dates'])) {
            $processed['dates'] = $this->standardizeDateData($processed['dates']);
        }
        
        // Standardize shipment data
        if (!empty($processed['shipment'])) {
            $processed['shipment'] = $this->standardizeShipmentData($processed['shipment']);
        }
        
        return $processed;
    }
    
    /**
     * Standardize vehicle data from AI
     */
    private function standardizeVehicleData(array $vehicle): array
    {
        // Standardize field names (AI might use different naming)
        $fieldMapping = [
            'make' => 'brand',
            'manufacturer' => 'brand',
            'brand_name' => 'brand',
            'car_make' => 'brand',
            'vehicle_make' => 'brand',
            'car_model' => 'model',
            'vehicle_model' => 'model',
            'model_name' => 'model',
            'manufacturing_year' => 'year',
            'model_year' => 'year',
            'production_year' => 'year',
            'engine_displacement' => 'engine_cc',
            'engine_size' => 'engine_cc',
            'displacement' => 'engine_cc',
            'vehicle_weight' => 'weight_kg',
            'curb_weight' => 'weight_kg',
            'gross_weight' => 'weight_kg',
            'odometer' => 'mileage_km',
            'mileage' => 'mileage_km',
            'kilometers' => 'mileage_km'
        ];
        
        // Apply field mapping
        foreach ($fieldMapping as $aiField => $standardField) {
            if (isset($vehicle[$aiField]) && !isset($vehicle[$standardField])) {
                $vehicle[$standardField] = $vehicle[$aiField];
                unset($vehicle[$aiField]);
            }
        }
        
        // Standardize values
        if (!empty($vehicle['fuel_type'])) {
            $vehicle['fuel_type'] = $this->standardizeFuelType($vehicle['fuel_type']);
        }
        
        if (!empty($vehicle['transmission'])) {
            $vehicle['transmission'] = $this->standardizeTransmission($vehicle['transmission']);
        }
        
        if (!empty($vehicle['condition'])) {
            $vehicle['condition'] = $this->standardizeCondition($vehicle['condition']);
        }
        
        // Standardize dimensions
        if (!empty($vehicle['dimensions'])) {
            $vehicle['dimensions'] = $this->standardizeDimensions($vehicle['dimensions']);
        }
        
        // Validate and clean numeric fields
        $numericFields = ['year', 'engine_cc', 'weight_kg', 'mileage_km'];
        foreach ($numericFields as $field) {
            if (isset($vehicle[$field])) {
                $vehicle[$field] = $this->cleanNumericValue($vehicle[$field]);
            }
        }
        
        // Validate VIN format
        if (!empty($vehicle['vin'])) {
            $vehicle['vin'] = $this->validateVin($vehicle['vin']);
        }
        
        return array_filter($vehicle, fn($value) => $value !== null && $value !== '');
    }
    
    /**
     * Standardize contact data
     */
    private function standardizeContactData(array $contact): array
    {
        // Clean email
        if (!empty($contact['email'])) {
            $contact['email'] = strtolower(trim($contact['email']));
            if (!filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) {
                unset($contact['email']);
            }
        }
        
        // Clean phone
        if (!empty($contact['phone'])) {
            $contact['phone'] = preg_replace('/[^\d+]/', '', $contact['phone']);
        }
        
        // Clean name
        if (!empty($contact['name'])) {
            $contact['name'] = trim($contact['name'], '"\'');
        }
        
        return array_filter($contact);
    }
    
    /**
     * Standardize pricing data
     */
    private function standardizePricingData(array $pricing): array
    {
        // Standardize currency
        if (!empty($pricing['currency'])) {
            $pricing['currency'] = strtoupper(trim($pricing['currency']));
            
            // Map common currency symbols
            $currencyMap = [
                '$' => 'USD',
                '€' => 'EUR', 
                '£' => 'GBP',
                'DOLLAR' => 'USD',
                'EURO' => 'EUR',
                'POUND' => 'GBP'
            ];
            
            if (isset($currencyMap[$pricing['currency']])) {
                $pricing['currency'] = $currencyMap[$pricing['currency']];
            }
        }
        
        // Clean amount
        if (!empty($pricing['amount'])) {
            $pricing['amount'] = $this->cleanNumericValue($pricing['amount']);
        }
        
        // Standardize incoterm
        if (!empty($pricing['incoterm'])) {
            $pricing['incoterm'] = strtoupper(trim($pricing['incoterm']));
        }
        
        return array_filter($pricing);
    }
    
    /**
     * Standardize date data
     */
    private function standardizeDateData(array $dates): array
    {
        foreach ($dates as $key => $date) {
            if (!empty($date)) {
                $dates[$key] = $this->standardizeDate($date);
            }
        }
        
        return array_filter($dates);
    }
    
    /**
     * Standardize shipment data
     */
    private function standardizeShipmentData(array $shipment): array
    {
        // Clean location names
        foreach (['origin', 'destination', 'origin_port', 'destination_port'] as $field) {
            if (!empty($shipment[$field])) {
                $shipment[$field] = trim($shipment[$field], '.,;');
            }
        }
        
        // Standardize shipping type
        if (!empty($shipment['shipping_type'])) {
            $shipment['shipping_type'] = strtolower(trim($shipment['shipping_type']));
        }
        
        // Standardize container size
        if (!empty($shipment['container_size'])) {
            $containerSize = strtolower(trim($shipment['container_size']));
            if (strpos($containerSize, '40') !== false && strpos($containerSize, 'hc') !== false) {
                $shipment['container_size'] = '40hc';
            } elseif (strpos($containerSize, '40') !== false) {
                $shipment['container_size'] = '40ft';
            } elseif (strpos($containerSize, '20') !== false) {
                $shipment['container_size'] = '20ft';
            }
        }
        
        return array_filter($shipment);
    }
    
    /**
     * Calculate AI extraction confidence
     */
    private function calculateAiConfidence(array $data): float
    {
        $score = 0;
        $maxScore = 0;
        
        // Vehicle confidence
        if (!empty($data['vehicle'])) {
            $vehicleScore = 0;
            $vehicleWeights = [
                'brand' => 15,
                'model' => 15,
                'year' => 10,
                'vin' => 20,
                'engine_cc' => 5,
                'fuel_type' => 5,
                'dimensions' => 10,
                'weight_kg' => 5
            ];
            
            foreach ($vehicleWeights as $field => $weight) {
                $maxScore += $weight;
                if (!empty($data['vehicle'][$field])) {
                    $vehicleScore += $weight;
                }
            }
            $score += $vehicleScore;
        }
        
        // Contact confidence
        if (!empty($data['contact'])) {
            $contactWeights = ['email' => 10, 'phone' => 5, 'name' => 3, 'company' => 2];
            foreach ($contactWeights as $field => $weight) {
                $maxScore += $weight;
                if (!empty($data['contact'][$field])) {
                    $score += $weight;
                }
            }
        }
        
        // Shipment confidence
        if (!empty($data['shipment'])) {
            $shipmentWeights = ['origin' => 8, 'destination' => 8, 'shipping_type' => 4];
            foreach ($shipmentWeights as $field => $weight) {
                $maxScore += $weight;
                if (!empty($data['shipment'][$field])) {
                    $score += $weight;
                }
            }
        }
        
        // Pricing confidence
        if (!empty($data['pricing'])) {
            $pricingWeights = ['amount' => 8, 'currency' => 2];
            foreach ($pricingWeights as $field => $weight) {
                $maxScore += $weight;
                if (!empty($data['pricing'][$field])) {
                    $score += $weight;
                }
            }
        }
        
        return $maxScore > 0 ? round($score / $maxScore, 2) : 0;
    }
    
    /**
     * Helper methods
     */
    private function standardizeFuelType(string $fuel): string
    {
        $fuel = strtolower(trim($fuel));
        
        if (in_array($fuel, ['diesel', 'petrol', 'gasoline', 'electric', 'hybrid', 'lpg', 'cng'])) {
            return $fuel === 'gasoline' ? 'petrol' : $fuel;
        }
        
        return 'diesel'; // default
    }
    
    private function standardizeTransmission(string $trans): string
    {
        $trans = strtolower(trim($trans));
        
        if (strpos($trans, 'auto') !== false) return 'automatic';
        if (strpos($trans, 'cvt') !== false) return 'cvt';
        
        return 'manual';
    }
    
    private function standardizeCondition(string $condition): string
    {
        $condition = strtolower(trim($condition));
        
        if (strpos($condition, 'new') !== false) return 'new';
        if (strpos($condition, 'damage') !== false || strpos($condition, 'accident') !== false) return 'damaged';
        
        return 'used';
    }
    
    private function standardizeDimensions(array $dimensions): array
    {
        $standardized = [];
        
        // Handle different dimension formats
        foreach (['length', 'width', 'height'] as $dim) {
            $value = null;
            
            // Try different field names
            $possibleFields = [
                $dim . '_m',
                $dim . '_meters', 
                $dim . '_cm',
                $dim . '_centimeters',
                $dim . '_mm',
                $dim . '_millimeters',
                $dim . '_ft',
                $dim . '_feet',
                $dim . '_in',
                $dim . '_inches',
                $dim
            ];
            
            foreach ($possibleFields as $field) {
                if (isset($dimensions[$field])) {
                    $value = $this->convertToMeters($dimensions[$field], $field);
                    break;
                }
            }
            
            if ($value !== null) {
                $standardized[$dim . '_m'] = $value;
            }
        }
        
        return $standardized;
    }
    
    private function convertToMeters(float $value, string $unit): float
    {
        if (strpos($unit, '_cm') !== false) {
            return round($value / 100, 3);
        } elseif (strpos($unit, '_mm') !== false) {
            return round($value / 1000, 3);
        } elseif (strpos($unit, '_ft') !== false || strpos($unit, '_feet') !== false) {
            return round($value * 0.3048, 3);
        } elseif (strpos($unit, '_in') !== false || strpos($unit, '_inches') !== false) {
            return round($value * 0.0254, 3);
        }
        
        return round($value, 3); // assume meters
    }
    
    private function cleanNumericValue($value): ?float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }
        
        if (is_string($value)) {
            // Remove common non-numeric characters
            $cleaned = preg_replace('/[^0-9.]/', '', $value);
            return is_numeric($cleaned) ? (float)$cleaned : null;
        }
        
        return null;
    }
    
    private function validateVin(string $vin): ?string
    {
        $vin = strtoupper(trim($vin));
        
        // Basic VIN validation
        if (strlen($vin) === 17 && preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) {
            return $vin;
        }
        
        return null;
    }
    
    private function standardizeDate(string $date): string
    {
        // Try to parse and standardize date format
        try {
            $parsed = new \DateTime($date);
            return $parsed->format('Y-m-d');
        } catch (\Exception $e) {
            return $date; // Return original if parsing fails
        }
    }
}

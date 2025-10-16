<?php

namespace App\Services\Commodity;

use App\Models\Intake;
use App\Models\Document;
use App\Services\VehicleDatabase\VehicleDatabaseService;
use Illuminate\Support\Facades\Log;

/**
 * CommodityMappingService
 * 
 * Maps extraction data from intakes/documents to commodity item structure
 * for auto-populating quotation forms.
 */
class CommodityMappingService
{
    public function __construct(
        private VehicleDatabaseService $vehicleDb
    ) {}

    /**
     * Map extraction data to commodity items
     * 
     * @param array $extractionData The extracted data from document/intake
     * @return array Array of commodity items ready for quotation form
     */
    public function mapFromExtractionData(array $extractionData): array
    {
        Log::info('CommodityMappingService: Starting mapping', [
            'data_keys' => array_keys($extractionData)
        ]);

        $commodityItems = [];

        // Handle nested structures (check both direct and raw_data)
        $rawData = $extractionData['raw_data'] ?? [];
        $documentData = $extractionData['document_data'] ?? [];

        // Try to extract vehicles
        $vehicles = $this->extractVehicles($extractionData, $rawData, $documentData);
        foreach ($vehicles as $vehicle) {
            $commodityItems[] = $this->mapVehicleData($vehicle);
        }

        // Try to extract machinery
        $machinery = $this->extractMachinery($extractionData, $rawData, $documentData);
        foreach ($machinery as $machine) {
            $commodityItems[] = $this->mapMachineryData($machine);
        }

        // Try to extract boats
        $boats = $this->extractBoats($extractionData, $rawData, $documentData);
        foreach ($boats as $boat) {
            $commodityItems[] = $this->mapBoatData($boat);
        }

        // If no specific commodity found, try general cargo
        if (empty($commodityItems)) {
            $cargoData = $this->extractGeneralCargo($extractionData, $rawData, $documentData);
            if (!empty($cargoData)) {
                $commodityItems[] = $this->mapGeneralCargoData($cargoData);
            }
        }

        Log::info('CommodityMappingService: Mapping complete', [
            'items_created' => count($commodityItems)
        ]);

        return $commodityItems;
    }

    /**
     * Extract vehicles from various data structures
     */
    private function extractVehicles(array $extractionData, array $rawData, array $documentData): array
    {
        $vehicles = [];

        // Try different locations where vehicle data might be
        $vehicleData = $documentData['vehicle'] ?? $extractionData['vehicle'] ?? $rawData['vehicle'] ?? null;

        if ($vehicleData) {
            // Single vehicle
            $vehicles[] = $vehicleData;
        } else {
            // Try flat structure from image extraction
            if (!empty($rawData['vehicle_make']) || !empty($extractionData['vehicle_make'])) {
                $vehicles[] = [
                    'make' => $rawData['vehicle_make'] ?? $extractionData['vehicle_make'] ?? null,
                    'model' => $rawData['vehicle_model'] ?? $extractionData['vehicle_model'] ?? null,
                    'year' => $rawData['vehicle_year'] ?? $extractionData['vehicle_year'] ?? null,
                    'condition' => $rawData['vehicle_condition'] ?? $extractionData['vehicle_condition'] ?? null,
                    'vin' => $rawData['vin'] ?? $extractionData['vin'] ?? null,
                    'dimensions' => $rawData['dimensions'] ?? $extractionData['dimensions'] ?? null,
                    'weight' => $rawData['weight'] ?? $extractionData['weight'] ?? null,
                    'fuel_type' => $rawData['fuel_type'] ?? $extractionData['fuel_type'] ?? null,
                    'color' => $rawData['color'] ?? $extractionData['color'] ?? null,
                ];
            }
        }

        // Check for multiple vehicles in cargo array
        if (isset($extractionData['cargo']) && is_array($extractionData['cargo'])) {
            foreach ($extractionData['cargo'] as $item) {
                if (is_array($item) && ($item['type'] ?? '') === 'vehicle') {
                    $vehicles[] = $item;
                }
            }
        }

        // Return vehicles that have at least some identifying information
        return array_filter($vehicles, fn($v) => 
            !empty($v['make']) || 
            !empty($v['vin']) || 
            !empty($v['dimensions']) ||
            !empty($v['weight'])
        );
    }

    /**
     * Extract machinery from data
     */
    private function extractMachinery(array $extractionData, array $rawData, array $documentData): array
    {
        $machinery = [];

        // Check cargo for machinery
        if (isset($extractionData['cargo']) && is_array($extractionData['cargo'])) {
            foreach ($extractionData['cargo'] as $item) {
                if (is_array($item) && in_array($item['type'] ?? '', ['machinery', 'equipment', 'machine'])) {
                    $machinery[] = $item;
                }
            }
        }

        // Check dedicated machinery field
        if (isset($documentData['machinery'])) {
            $machinery[] = $documentData['machinery'];
        }

        return $machinery;
    }

    /**
     * Extract boats from data
     */
    private function extractBoats(array $extractionData, array $rawData, array $documentData): array
    {
        $boats = [];

        // Check cargo for boats
        if (isset($extractionData['cargo']) && is_array($extractionData['cargo'])) {
            foreach ($extractionData['cargo'] as $item) {
                if (is_array($item) && in_array($item['type'] ?? '', ['boat', 'yacht', 'vessel'])) {
                    $boats[] = $item;
                }
            }
        }

        return $boats;
    }

    /**
     * Extract general cargo data
     */
    private function extractGeneralCargo(array $extractionData, array $rawData, array $documentData): ?array
    {
        $cargo = $documentData['cargo'] ?? $extractionData['cargo'] ?? $rawData['cargo'] ?? null;

        if ($cargo && !is_array($cargo)) {
            // If cargo is a string (description)
            return ['description' => $cargo];
        }

        // If we have any cargo description
        if (!empty($rawData['cargo_description']) || !empty($extractionData['cargo_description'])) {
            return [
                'description' => $rawData['cargo_description'] ?? $extractionData['cargo_description'],
                'dimensions' => $rawData['dimensions'] ?? $extractionData['dimensions'] ?? null,
                'weight' => $rawData['weight'] ?? $extractionData['weight'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Map vehicle data to commodity item structure
     */
    public function mapVehicleData(array $data): array
    {
        Log::debug('Mapping vehicle data', ['data' => $data]);

        $item = [
            'commodity_type' => 'vehicles',
            'vehicle_category' => $this->detectVehicleCategory($data),
        ];

        // Make and Model
        $item['make'] = $data['make'] ?? $data['brand'] ?? null;
        $item['type_model'] = $data['model'] ?? null;

        // VIN
        $item['vin'] = $data['vin'] ?? $data['chassis_number'] ?? null;

        // If we have VIN, try vehicle database lookup
        if ($item['vin'] && empty($item['make'])) {
            $vehicleInfo = $this->lookupVehicleByVIN($item['vin']);
            if ($vehicleInfo) {
                $item['make'] = $item['make'] ?? $vehicleInfo['make'];
                $item['type_model'] = $item['type_model'] ?? $vehicleInfo['model'];
                $item['year'] = $vehicleInfo['year'] ?? null;
            }
        }

        // Dimensions
        $dimensions = $this->parseDimensions($data['dimensions'] ?? null);
        if ($dimensions) {
            $item['length_cm'] = $dimensions['length'];
            $item['width_cm'] = $dimensions['width'];
            $item['height_cm'] = $dimensions['height'];
        }

        // Weight
        $weight = $this->parseWeight($data['weight'] ?? $data['weight_kg'] ?? null);
        if ($weight) {
            $item['weight_kg'] = $weight;
        }

        // Condition
        $item['condition'] = $this->normalizeCondition($data['condition'] ?? null);

        // Fuel Type
        $item['fuel_type'] = $this->normalizeFuelType($data['fuel_type'] ?? null);

        // Color, Year
        $item['color'] = $data['color'] ?? null;
        $item['year'] = $data['year'] ?? null;

        // Extra info (any additional details)
        $extraInfo = [];
        if (!empty($data['mileage_km'])) $extraInfo[] = "Mileage: {$data['mileage_km']} km";
        if (!empty($data['engine_cc'])) $extraInfo[] = "Engine: {$data['engine_cc']} cc";
        if (!empty($data['description'])) $extraInfo[] = $data['description'];
        
        if (!empty($extraInfo)) {
            $item['extra_info'] = implode('. ', $extraInfo);
        }

        // Calculate CBM if dimensions present
        if (!empty($item['length_cm']) && !empty($item['width_cm']) && !empty($item['height_cm'])) {
            $item['cbm'] = round(
                ($item['length_cm'] / 100) * 
                ($item['width_cm'] / 100) * 
                ($item['height_cm'] / 100),
                3
            );
        }

        return array_filter($item, fn($v) => $v !== null);
    }

    /**
     * Map machinery data to commodity item structure
     */
    public function mapMachineryData(array $data): array
    {
        $item = [
            'commodity_type' => 'machinery',
            'make' => $data['make'] ?? $data['manufacturer'] ?? null,
            'type_model' => $data['model'] ?? $data['type'] ?? null,
        ];

        // Dimensions
        $dimensions = $this->parseDimensions($data['dimensions'] ?? null);
        if ($dimensions) {
            $item['length_cm'] = $dimensions['length'];
            $item['width_cm'] = $dimensions['width'];
            $item['height_cm'] = $dimensions['height'];
        }

        // Weight
        $weight = $this->parseWeight($data['weight'] ?? null);
        if ($weight) {
            $item['weight_kg'] = $weight;
        }

        // Condition
        $item['condition'] = $this->normalizeCondition($data['condition'] ?? null);

        // Fuel Type
        $item['fuel_type'] = $this->normalizeFuelType($data['fuel_type'] ?? $data['power_source'] ?? null);

        // Parts
        if (!empty($data['includes_parts']) || !empty($data['parts'])) {
            $item['parts'] = true;
            $item['parts_description'] = $data['parts_description'] ?? $data['parts'] ?? null;
        }

        // Extra info
        if (!empty($data['description'])) {
            $item['extra_info'] = $data['description'];
        }

        // Calculate CBM
        if (!empty($item['length_cm']) && !empty($item['width_cm']) && !empty($item['height_cm'])) {
            $item['cbm'] = round(
                ($item['length_cm'] / 100) * 
                ($item['width_cm'] / 100) * 
                ($item['height_cm'] / 100),
                3
            );
        }

        return array_filter($item, fn($v) => $v !== null);
    }

    /**
     * Map boat data to commodity item structure
     */
    public function mapBoatData(array $data): array
    {
        $item = [
            'commodity_type' => 'boat',
        ];

        // Dimensions (boats might have only L x W, no height)
        $dimensions = $this->parseBoatDimensions($data['dimensions'] ?? $data['length'] ?? null);
        if ($dimensions) {
            $item['length_cm'] = $dimensions['length'] ?? null;
            $item['width_cm'] = $dimensions['width'] ?? $dimensions['beam'] ?? null;
            $item['height_cm'] = $dimensions['height'] ?? null;
        }

        // Weight
        $weight = $this->parseWeight($data['weight'] ?? $data['displacement'] ?? null);
        if ($weight) {
            $item['weight_kg'] = $weight;
        }

        // Condition
        $item['condition'] = $this->normalizeCondition($data['condition'] ?? null);

        // Trailer and Cradle
        if (!empty($data['trailer']) || str_contains(strtolower($data['description'] ?? ''), 'trailer')) {
            $item['trailer'] = true;
        }

        if (!empty($data['cradle']) || str_contains(strtolower($data['description'] ?? ''), 'cradle')) {
            $cradleType = strtolower($data['cradle_type'] ?? '');
            if (str_contains($cradleType, 'wood')) {
                $item['wooden_cradle'] = true;
            } elseif (str_contains($cradleType, 'iron') || str_contains($cradleType, 'metal')) {
                $item['iron_cradle'] = true;
            } else {
                $item['wooden_cradle'] = true; // Default
            }
        }

        // Extra info
        $extraInfo = [];
        if (!empty($data['make'])) $extraInfo[] = "Make: {$data['make']}";
        if (!empty($data['model'])) $extraInfo[] = "Model: {$data['model']}";
        if (!empty($data['year'])) $extraInfo[] = "Year: {$data['year']}";
        if (!empty($data['description'])) $extraInfo[] = $data['description'];
        
        if (!empty($extraInfo)) {
            $item['extra_info'] = implode('. ', $extraInfo);
        }

        return array_filter($item, fn($v) => $v !== null);
    }

    /**
     * Map general cargo data to commodity item structure
     */
    public function mapGeneralCargoData(array $data): array
    {
        $item = [
            'commodity_type' => 'general_cargo',
        ];

        // Cargo Type
        $item['cargo_type'] = $this->detectCargoType($data);

        // Dimensions
        $dimensions = $this->parseDimensions($data['dimensions'] ?? null);
        if ($dimensions) {
            $item['length_cm'] = $dimensions['length'];
            $item['width_cm'] = $dimensions['width'];
            $item['height_cm'] = $dimensions['height'];
        }

        // Weight (both bruto and netto)
        if (!empty($data['bruto_weight']) || !empty($data['gross_weight'])) {
            $item['bruto_weight_kg'] = $this->parseWeight($data['bruto_weight'] ?? $data['gross_weight']);
        }
        
        if (!empty($data['netto_weight']) || !empty($data['net_weight'])) {
            $item['netto_weight_kg'] = $this->parseWeight($data['netto_weight'] ?? $data['net_weight']);
        }
        
        // If only one weight given, use it for bruto
        if (empty($item['bruto_weight_kg']) && !empty($data['weight'])) {
            $item['bruto_weight_kg'] = $this->parseWeight($data['weight']);
        }

        // Flags
        if (!empty($data['forkliftable']) || str_contains(strtolower($data['description'] ?? ''), 'forklift')) {
            $item['forkliftable'] = true;
        }

        if (!empty($data['hazardous']) || str_contains(strtolower($data['description'] ?? ''), 'hazard')) {
            $item['hazardous'] = true;
        }

        if (!empty($data['unpacked']) || str_contains(strtolower($data['description'] ?? ''), 'unpacked')) {
            $item['unpacked'] = true;
        }

        if (!empty($data['ispm15']) || str_contains(strtolower($data['description'] ?? ''), 'ispm')) {
            $item['ispm15_wood'] = true;
        }

        // Extra info
        if (!empty($data['description'])) {
            $item['extra_info'] = $data['description'];
        }

        // Calculate CBM
        if (!empty($item['length_cm']) && !empty($item['width_cm']) && !empty($item['height_cm'])) {
            $item['cbm'] = round(
                ($item['length_cm'] / 100) * 
                ($item['width_cm'] / 100) * 
                ($item['height_cm'] / 100),
                3
            );
        }

        return array_filter($item, fn($v) => $v !== null);
    }

    /**
     * Detect vehicle category from data
     */
    private function detectVehicleCategory(array $data): string
    {
        $model = strtolower($data['model'] ?? '');
        $type = strtolower($data['type'] ?? '');
        $category = strtolower($data['category'] ?? '');

        // Check for specific categories
        if (str_contains($model, 'suv') || str_contains($type, 'suv') || str_contains($category, 'suv')) {
            return 'suv';
        }

        if (str_contains($model, 'truck') || str_contains($type, 'truck') || str_contains($category, 'truck')) {
            return 'truck';
        }

        if (str_contains($model, 'van') || str_contains($type, 'van') || str_contains($category, 'van')) {
            return 'van';
        }

        if (str_contains($model, 'bus') || str_contains($type, 'bus') || str_contains($category, 'bus')) {
            return 'bus';
        }

        if (str_contains($model, 'motorcycle') || str_contains($model, 'bike') || str_contains($type, 'motorcycle')) {
            return 'motorcycle';
        }

        // Default to car
        return 'car';
    }

    /**
     * Detect cargo type from data
     */
    private function detectCargoType(array $data): string
    {
        $desc = strtolower($data['description'] ?? '');

        if (str_contains($desc, 'pallet')) return 'palletized';
        if (str_contains($desc, 'crate')) return 'crated';
        if (str_contains($desc, 'box')) return 'boxed';
        if (str_contains($desc, 'loose')) return 'loose';

        return 'loose'; // Default
    }

    /**
     * Parse boat dimensions (can be L x W or L x W x H)
     */
    private function parseBoatDimensions($dimensionsInput): ?array
    {
        if (empty($dimensionsInput)) {
            return null;
        }

        // Parse string format for boats (can be 2D or 3D)
        if (is_string($dimensionsInput)) {
            // Match 2D pattern: "8m x 2.5m"
            if (preg_match('/(\d+\.?\d*)\s*([a-z]*)\s*[x×]\s*(\d+\.?\d*)\s*([a-z]*)$/i', $dimensionsInput, $matches)) {
                $length = (float)$matches[1];
                $width = (float)$matches[3];
                
                $unit = strtolower($matches[2] ?: $matches[4] ?: 'cm');

                // Convert to cm
                if ($unit === 'm' || $unit === 'meter') {
                    $length *= 100;
                    $width *= 100;
                }

                return [
                    'length' => round($length, 2),
                    'width' => round($width, 2),
                ];
            }
        }

        // Fall back to regular dimension parsing for 3D
        return $this->parseDimensions($dimensionsInput);
    }

    /**
     * Parse dimensions from various formats
     * Formats: "4.9m x 1.8m x 1.4m", "490cm x 180cm x 140cm", "490 x 180 x 140"
     */
    private function parseDimensions($dimensionsInput): ?array
    {
        if (empty($dimensionsInput)) {
            return null;
        }

        // If already structured
        if (is_array($dimensionsInput)) {
            $length = $this->extractDimensionValue($dimensionsInput['length'] ?? $dimensionsInput['l'] ?? null);
            $width = $this->extractDimensionValue($dimensionsInput['width'] ?? $dimensionsInput['w'] ?? $dimensionsInput['beam'] ?? null);
            $height = $this->extractDimensionValue($dimensionsInput['height'] ?? $dimensionsInput['h'] ?? null);

            if ($length || $width || $height) {
                return array_filter([
                    'length' => $length,
                    'width' => $width,
                    'height' => $height,
                ]);
            }
        }

        // Parse string format
        if (is_string($dimensionsInput)) {
            // Match patterns like "4.9m x 1.8m x 1.4m" or "490 x 180 x 140"
            if (preg_match('/(\d+\.?\d*)\s*([a-z]*)\s*[x×]\s*(\d+\.?\d*)\s*([a-z]*)\s*[x×]\s*(\d+\.?\d*)\s*([a-z]*)/i', $dimensionsInput, $matches)) {
                $length = (float)$matches[1];
                $width = (float)$matches[3];
                $height = (float)$matches[5];
                
                $unit = strtolower($matches[2] ?: $matches[4] ?: $matches[6] ?: 'cm');

                // Convert to cm
                if ($unit === 'm' || $unit === 'meter') {
                    $length *= 100;
                    $width *= 100;
                    $height *= 100;
                } elseif ($unit === 'in' || $unit === 'inch') {
                    $length *= 2.54;
                    $width *= 2.54;
                    $height *= 2.54;
                } elseif ($unit === 'ft' || $unit === 'foot' || $unit === 'feet') {
                    $length *= 30.48;
                    $width *= 30.48;
                    $height *= 30.48;
                }

                return [
                    'length' => round($length, 2),
                    'width' => round($width, 2),
                    'height' => round($height, 2),
                ];
            }
        }

        return null;
    }

    /**
     * Extract dimension value from mixed input
     */
    private function extractDimensionValue($input): ?float
    {
        if (empty($input)) {
            return null;
        }

        if (is_numeric($input)) {
            return (float)$input;
        }

        if (is_array($input) && isset($input['value'])) {
            $value = (float)$input['value'];
            $unit = strtolower($input['unit'] ?? 'cm');

            // Convert to cm
            if ($unit === 'm') {
                $value *= 100;
            } elseif ($unit === 'in') {
                $value *= 2.54;
            } elseif ($unit === 'ft') {
                $value *= 30.48;
            }

            return round($value, 2);
        }

        // Try to extract number from string
        if (is_string($input) && preg_match('/(\d+\.?\d*)\s*([a-z]*)/i', $input, $matches)) {
            $value = (float)$matches[1];
            $unit = strtolower($matches[2] ?: 'cm');

            if ($unit === 'm') {
                $value *= 100;
            } elseif ($unit === 'in') {
                $value *= 2.54;
            } elseif ($unit === 'ft') {
                $value *= 30.48;
            }

            return round($value, 2);
        }

        return null;
    }

    /**
     * Parse weight from various formats
     */
    private function parseWeight($weightInput): ?float
    {
        if (empty($weightInput)) {
            return null;
        }

        if (is_numeric($weightInput)) {
            return (float)$weightInput;
        }

        if (is_array($weightInput)) {
            $value = (float)($weightInput['value'] ?? 0);
            $unit = strtolower($weightInput['unit'] ?? 'kg');

            // Convert to kg
            if ($unit === 'lbs' || $unit === 'lb') {
                $value *= 0.453592;
            } elseif ($unit === 't' || $unit === 'ton') {
                $value *= 1000;
            }

            return round($value, 2);
        }

        // Parse string
        if (is_string($weightInput) && preg_match('/(\d+\.?\d*)\s*([a-z]*)/i', $weightInput, $matches)) {
            $value = (float)$matches[1];
            $unit = strtolower($matches[2] ?: 'kg');

            if ($unit === 'lbs' || $unit === 'lb') {
                $value *= 0.453592;
            } elseif ($unit === 't' || $unit === 'ton') {
                $value *= 1000;
            }

            return round($value, 2);
        }

        return null;
    }

    /**
     * Normalize condition values
     */
    private function normalizeCondition(?string $condition): ?string
    {
        if (empty($condition)) {
            return null;
        }

        $condition = strtolower($condition);

        if (str_contains($condition, 'new')) return 'new';
        if (str_contains($condition, 'used')) return 'used';
        if (str_contains($condition, 'damaged')) return 'damaged';

        return 'used'; // Default
    }

    /**
     * Normalize fuel type values
     */
    private function normalizeFuelType(?string $fuelType): ?string
    {
        if (empty($fuelType)) {
            return null;
        }

        $fuelType = strtolower($fuelType);

        // Check hybrid first (before electric) since "hybrid electric" contains both
        if (str_contains($fuelType, 'hybrid')) return 'hybrid';
        if (str_contains($fuelType, 'gasoline') || str_contains($fuelType, 'petrol')) return 'gasoline';
        if (str_contains($fuelType, 'diesel')) return 'diesel';
        if (str_contains($fuelType, 'electric')) return 'electric';
        if (str_contains($fuelType, 'lpg') || str_contains($fuelType, 'gas')) return 'lpg';

        return null;
    }

    /**
     * Lookup vehicle by VIN using vehicle database
     */
    private function lookupVehicleByVIN(string $vin): ?array
    {
        try {
            $result = $this->vehicleDb->decodeVIN($vin);
            
            if ($result && !empty($result['manufacturer'])) {
                return [
                    'make' => $result['manufacturer'],
                    'year' => $result['year'] ?? null,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('VIN lookup failed', ['vin' => $vin, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Map from Intake model directly
     */
    public function mapFromIntake(Intake $intake): array
    {
        // Get aggregated extraction data if multi-document
        if ($intake->is_multi_document && $intake->aggregated_extraction_data) {
            return $this->mapFromExtractionData($intake->aggregated_extraction_data);
        }

        // Get first document's extraction data
        $document = $intake->documents()->first();
        if ($document && $document->extraction_data) {
            return $this->mapFromExtractionData($document->extraction_data);
        }

        return [];
    }

    /**
     * Map from Document model directly
     */
    public function mapFromDocument(Document $document): array
    {
        if ($document->extraction_data) {
            return $this->mapFromExtractionData($document->extraction_data);
        }

        return [];
    }
}


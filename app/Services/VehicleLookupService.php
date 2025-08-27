<?php

namespace App\Services;

use App\Models\VehicleSpec;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class VehicleLookupService
{
    /**
     * Find vehicle specifications based on extracted data
     */
    public function findVehicleSpecs(array $extractedVehicleData): ?VehicleSpec
    {
        // Extract make and model from various possible fields
        $make = $this->extractMake($extractedVehicleData);
        $model = $this->extractModel($extractedVehicleData);
        $year = $this->extractYear($extractedVehicleData);
        
        if (empty($make) || empty($model)) {
            return null;
        }
        
        // Try exact match first
        $specs = $this->searchByMakeModel($make, $model, $year);
        
        // If no exact match, try fuzzy matching
        if (!$specs) {
            $specs = $this->fuzzySearchByMakeModel($make, $model, $year);
        }
        
        return $specs;
    }
    
    /**
     * Enrich extracted vehicle data with database specifications
     */
    public function enrichVehicleData(array $extractedVehicleData): array
    {
        $specs = $this->findVehicleSpecs($extractedVehicleData);
        
        if (!$specs) {
            return $extractedVehicleData;
        }
        
        // Merge database specs with extracted data
        $enrichedData = array_merge($extractedVehicleData, [
            'database_match' => true,
            'spec_id' => $specs->id,
            'dimensions' => [
                'length_m' => $specs->length_m,
                'width_m' => $specs->width_m,
                'height_m' => $specs->height_m,
                'wheelbase_m' => $specs->wheelbase_m,
            ],
            'weight_kg' => $specs->weight_kg,
            'engine_cc' => $specs->engine_cc,
            'fuel_type' => $specs->fuel_type,
            'verified_specs' => true,
        ]);
        
        // Update full_name if not present
        if (empty($enrichedData['full_name'])) {
            $enrichedData['full_name'] = $specs->getFullNameAttribute();
        }
        
        return $enrichedData;
    }
    
    /**
     * Search for vehicles by make and model
     */
    private function searchByMakeModel(string $make, string $model, ?int $year = null): ?VehicleSpec
    {
        $query = VehicleSpec::whereRaw('LOWER(make) = ?', [strtolower($make)])
                          ->whereRaw('LOWER(model) = ?', [strtolower($model)]);
        
        if ($year) {
            // Find specs within 5 years of the target year
            $query->whereBetween('year', [$year - 5, $year + 5])
                  ->orderByRaw('ABS(year - ?) ASC', [$year]);
        }
        
        return $query->first();
    }
    
    /**
     * Fuzzy search for vehicles when exact match fails
     */
    private function fuzzySearchByMakeModel(string $make, string $model, ?int $year = null): ?VehicleSpec
    {
        // Try variations of the model name
        $modelVariations = $this->generateModelVariations($model);
        
        foreach ($modelVariations as $variation) {
            $result = $this->searchByMakeModel($make, $variation, $year);
            if ($result) {
                return $result;
            }
        }
        
        // Try partial matching
        $query = VehicleSpec::whereRaw('LOWER(make) LIKE ?', ['%' . strtolower($make) . '%'])
                          ->where(function ($q) use ($model) {
                              $q->whereRaw('LOWER(model) LIKE ?', ['%' . strtolower($model) . '%'])
                                ->orWhereRaw('LOWER(variant) LIKE ?', ['%' . strtolower($model) . '%']);
                          });
        
        if ($year) {
            $query->whereBetween('year', [$year - 10, $year + 10])
                  ->orderByRaw('ABS(year - ?) ASC', [$year]);
        }
        
        return $query->first();
    }
    
    /**
     * Generate model name variations for fuzzy matching
     */
    private function generateModelVariations(string $model): array
    {
        $variations = [$model];
        
        // Common replacements
        $replacements = [
            'Série' => 'Series',
            'Series' => 'Série',
            ' ' => '',
            '-' => ' ',
            '_' => ' ',
        ];
        
        foreach ($replacements as $from => $to) {
            $variation = str_replace($from, $to, $model);
            if ($variation !== $model) {
                $variations[] = $variation;
            }
        }
        
        // Remove duplicates
        return array_unique($variations);
    }
    
    /**
     * Extract make from vehicle data
     */
    private function extractMake(array $data): string
    {
        return $data['brand'] ?? $data['make'] ?? '';
    }
    
    /**
     * Extract model from vehicle data
     */
    private function extractModel(array $data): string
    {
        // Try model field first
        if (!empty($data['model'])) {
            return $data['model'];
        }
        
        // Try to extract from full_name
        if (!empty($data['full_name'])) {
            $make = $this->extractMake($data);
            if (!empty($make)) {
                return trim(str_replace($make, '', $data['full_name']));
            }
            return $data['full_name'];
        }
        
        return '';
    }
    
    /**
     * Extract year from vehicle data
     */
    private function extractYear(array $data): ?int
    {
        $year = $data['year'] ?? null;
        
        if (is_string($year) && is_numeric($year)) {
            return (int) $year;
        }
        
        if (is_int($year)) {
            return $year;
        }
        
        return null;
    }
    
    /**
     * Get all available makes
     */
    public function getAvailableMakes(): Collection
    {
        return VehicleSpec::distinct()
                         ->pluck('make')
                         ->sort()
                         ->values();
    }
    
    /**
     * Get models for a specific make
     */
    public function getModelsForMake(string $make): Collection
    {
        return VehicleSpec::whereRaw('LOWER(make) = ?', [strtolower($make)])
                         ->distinct()
                         ->pluck('model')
                         ->sort()
                         ->values();
    }
    
    /**
     * Calculate shipping volume from vehicle specs
     */
    public function calculateShippingVolume(VehicleSpec $specs): float
    {
        return round($specs->length_m * $specs->width_m * $specs->height_m, 3);
    }
    
    /**
     * Format vehicle specs for display
     */
    public function formatSpecsForDisplay(VehicleSpec $specs): array
    {
        return [
            'vehicle' => $specs->getFullNameAttribute(),
            'year' => $specs->year,
            'dimensions' => [
                'length' => $specs->length_m . ' m',
                'width' => $specs->width_m . ' m',
                'height' => $specs->height_m . ' m',
                'wheelbase' => $specs->wheelbase_m . ' m',
            ],
            'weight' => $specs->weight_kg . ' kg',
            'engine' => $specs->engine_cc . ' cc',
            'fuel_type' => ucfirst($specs->fuel_type),
            'volume' => $this->calculateShippingVolume($specs) . ' m³',
            'is_electric' => $specs->isElectric(),
        ];
    }
}

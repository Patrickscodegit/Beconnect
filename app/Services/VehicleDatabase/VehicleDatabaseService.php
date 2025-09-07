<?php

namespace App\Services\VehicleDatabase;

use App\Models\VehicleSpec;
use App\Models\VinWmi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VehicleDatabaseService
{
    /**
     * Brand aliases for better matching
     */
    private array $brandAliases = [
        'b m w' => 'bmw',
        'bayerische motoren werke' => 'bmw',
        'mercedes-benz' => 'mercedes',
        'rolls royce' => 'rolls-royce',
        'volkswagen' => 'vw',
        'range rover' => 'land rover',
    ];

    /**
     * Normalize string for database matching
     */
    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s; // Série -> Serie
        return preg_replace('/\s+/', ' ', $s);
    }

    /**
     * Apply brand aliases for better matching
     */
    private function aliasBrand(string $brand): string
    {
        $n = $this->norm($brand);
        return $this->brandAliases[$n] ?? $n;
    }

    /**
     * Find vehicle in database using multiple matching strategies
     */
    public function findVehicle(array $vehicleData): ?VehicleSpec
    {
        // Strategy 1: Exact match
        if ($exact = $this->findExactMatch($vehicleData)) {
            Log::info('Vehicle found via exact match', ['vehicle_id' => $exact->id]);
            return $exact;
        }
        
        // Strategy 2: Fuzzy match
        if ($fuzzy = $this->findFuzzyMatch($vehicleData)) {
            Log::info('Vehicle found via fuzzy match', ['vehicle_id' => $fuzzy->id]);
            return $fuzzy;
        }
        
        // Strategy 3: Partial match
        if ($partial = $this->findPartialMatch($vehicleData)) {
            Log::info('Vehicle found via partial match', ['vehicle_id' => $partial->id]);
            return $partial;
        }
        
        Log::info('No vehicle match found in database', $vehicleData);
        return null;
    }
    
    /**
     * Check if vehicle has dimensions available in database
     */
    public function hasVehicleDimensions(array $vehicleData): bool
    {
        $vehicle = $this->findVehicle($vehicleData);
        
        if (!$vehicle) {
            return false;
        }
        
        return !empty($vehicle->length_m) && !empty($vehicle->width_m) && !empty($vehicle->height_m);
    }
    
    /**
     * Get dimensions for a vehicle if available in database
     */
    public function getVehicleDimensions(array $vehicleData): ?array
    {
        $vehicle = $this->findVehicle($vehicleData);
        
        if (!$vehicle || !$this->hasVehicleDimensions($vehicleData)) {
            return null;
        }
        
        return [
            'length_m' => (float) $vehicle->length_m,
            'width_m' => (float) $vehicle->width_m,
            'height_m' => (float) $vehicle->height_m,
            'source' => 'database',
            'vehicle_id' => $vehicle->id,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year
        ];
    }
    
    /**
     * Find exact match by make, model, and optionally year
     */
    private function findExactMatch(array $data): ?VehicleSpec
    {
        if (empty($data['brand']) && empty($data['make'])) {
            return null;
        }
        
        $brand = $data['brand'] ?? $data['make'];
        $model = $data['model'] ?? '';
        
        // Apply normalization and aliases
        $normalizedBrand = $this->aliasBrand($brand);
        $normalizedModel = $this->norm($model);
        
        $query = VehicleSpec::whereRaw("LOWER(REPLACE(make, ' ', '')) LIKE ?", [str_replace(' ', '', $normalizedBrand)]);
        
        if (!empty($model)) {
            $query->whereRaw("LOWER(REPLACE(model, ' ', '')) LIKE ?", [str_replace(' ', '', $normalizedModel)]);
        }
        
        if (!empty($data['year'])) {
            $query->where('year', $data['year']);
        }
        
        return $query->first();
    }
    
    /**
     * Find fuzzy match using LIKE patterns for SQLite compatibility
     */
    private function findFuzzyMatch(array $data): ?VehicleSpec
    {
        if (empty($data['brand']) && empty($data['make'])) {
            return null;
        }
        
        $brand = strtolower($data['brand'] ?? $data['make']);
        $model = strtolower($data['model'] ?? '');
        
        // Try partial matches with PostgreSQL-compatible LIKE
        $result = VehicleSpec::whereRaw('LOWER(make) LIKE ?', ["%{$brand}%"])
                            ->when($model, function($query) use ($model) {
                                return $query->whereRaw('LOWER(model) LIKE ?', ["%{$model}%"]);
                            })
                            ->first();
        
        if ($result) {
            return $result;
        }
        
        // Try brand-only partial match
        return VehicleSpec::whereRaw('LOWER(make) LIKE ?', ["%{$brand}%"])
                         ->orderBy('year', 'desc')
                         ->first();
    }
    
    /**
     * Find partial match by brand only
     */
    private function findPartialMatch(array $data): ?VehicleSpec
    {
        if (empty($data['brand']) && empty($data['make'])) {
            return null;
        }
        
        $brand = $data['brand'] ?? $data['make'];
        
        // Find most common model for this brand
        return VehicleSpec::whereRaw('LOWER(make) LIKE ?', [strtolower($brand)])
                         ->orderBy('year', 'desc')
                         ->first();
    }
    
    /**
     * Decode VIN using WMI database
     */
    public function decodeVIN(string $vin): ?array
    {
        if (strlen($vin) !== 17) {
            return null;
        }
        
        $wmi = substr($vin, 0, 3);
        
        // Check cache first
        $cacheKey = "vin_decode_{$wmi}";
        
        return Cache::remember($cacheKey, 3600, function() use ($wmi, $vin) {
            $wmiRecord = VinWmi::where('wmi', $wmi)->first();
            
            if (!$wmiRecord) {
                return null;
            }
            
            // Extract year from VIN (position 10)
            $yearChar = $vin[9];
            $year = $this->decodeVinYear($yearChar);
            
            return [
                'wmi' => $wmi,
                'manufacturer' => $wmiRecord->manufacturer,
                'country' => $wmiRecord->country,
                'country_code' => $wmiRecord->country_code,
                'year' => $year,
                'wmi_start_year' => $wmiRecord->start_year,
                'wmi_end_year' => $wmiRecord->end_year
            ];
        });
    }
    
    /**
     * Decode year from VIN character
     */
    private function decodeVinYear(string $yearChar): ?int
    {
        // VIN year encoding mapping
        $yearMap = [
            // 1980s-1990s
            'A' => 1980, 'B' => 1981, 'C' => 1982, 'D' => 1983, 'E' => 1984,
            'F' => 1985, 'G' => 1986, 'H' => 1987, 'J' => 1988, 'K' => 1989,
            'L' => 1990, 'M' => 1991, 'N' => 1992, 'P' => 1993, 'R' => 1994,
            'S' => 1995, 'T' => 1996, 'V' => 1997, 'W' => 1998, 'X' => 1999,
            'Y' => 2000,
            // 2000s
            '1' => 2001, '2' => 2002, '3' => 2003, '4' => 2004, '5' => 2005,
            '6' => 2006, '7' => 2007, '8' => 2008, '9' => 2009,
            // 2010s-2020s
            'A' => 2010, 'B' => 2011, 'C' => 2012, 'D' => 2013, 'E' => 2014,
            'F' => 2015, 'G' => 2016, 'H' => 2017, 'J' => 2018, 'K' => 2019,
            'L' => 2020, 'M' => 2021, 'N' => 2022, 'P' => 2023, 'R' => 2024,
            'S' => 2025, 'T' => 2026, 'V' => 2027, 'W' => 2028, 'X' => 2029,
        ];
        
        return $yearMap[strtoupper($yearChar)] ?? null;
    }
    
    /**
     * Get all vehicle brands for pattern matching
     */
    public function getAllBrands(): Collection
    {
        return Cache::remember('vehicle_brands', 3600, function() {
            return VehicleSpec::distinct('make')
                             ->orderBy('make')
                             ->pluck('make');
        });
    }
    
    /**
     * Get vehicle models for a specific brand
     */
    public function getModelsForBrand(string $brand): Collection
    {
        $cacheKey = "vehicle_models_" . strtolower($brand);
        
        return Cache::remember($cacheKey, 3600, function() use ($brand) {
            return VehicleSpec::whereRaw('LOWER(make) LIKE ?', [strtolower($brand)])
                             ->distinct('model')
                             ->orderBy('model')
                             ->pluck('model');
        });
    }
    
    /**
     * Get brand-model patterns for extraction
     */
    public function getBrandModelPatterns(): array
    {
        return Cache::remember('brand_model_patterns', 3600, function() {
            $patterns = [];
            
            $vehicles = VehicleSpec::select('make', 'model')
                                  ->distinct()
                                  ->orderBy('make')
                                  ->orderBy('model')
                                  ->get();
            
            foreach ($vehicles as $vehicle) {
                $patterns[] = [
                    'pattern' => $vehicle->make . ' ' . $vehicle->model,
                    'brand' => $vehicle->make,
                    'model' => $vehicle->model,
                    'regex' => '/\b' . preg_quote($vehicle->make, '/') . '\s+' . preg_quote($vehicle->model, '/') . '\b/i'
                ];
            }
            
            // Sort by pattern length (longer patterns first for better matching)
            usort($patterns, fn($a, $b) => strlen($b['pattern']) - strlen($a['pattern']));
            
            return $patterns;
        });
    }
    
    /**
     * Validate vehicle data against database
     */
    public function validateVehicleData(array $data): array
    {
        $validation = [
            'valid' => true,
            'warnings' => [],
            'corrections' => []
        ];
        
        // Validate year range
        if (!empty($data['year'])) {
            $currentYear = date('Y');
            if ($data['year'] < 1900 || $data['year'] > $currentYear + 2) {
                $validation['warnings'][] = "Year {$data['year']} seems unusual";
                $validation['valid'] = false;
            }
        }
        
        // Validate engine CC range
        if (!empty($data['engine_cc'])) {
            if ($data['engine_cc'] < 500 || $data['engine_cc'] > 8000) {
                $validation['warnings'][] = "Engine CC {$data['engine_cc']} seems unusual";
            }
        }
        
        // Validate dimensions
        if (!empty($data['dimensions'])) {
            $dims = $data['dimensions'];
            if (!empty($dims['length_m']) && ($dims['length_m'] < 2 || $dims['length_m'] > 15)) {
                $validation['warnings'][] = "Length {$dims['length_m']}m seems unusual";
            }
            if (!empty($dims['width_m']) && ($dims['width_m'] < 1 || $dims['width_m'] > 3)) {
                $validation['warnings'][] = "Width {$dims['width_m']}m seems unusual";
            }
            if (!empty($dims['height_m']) && ($dims['height_m'] < 1 || $dims['height_m'] > 4)) {
                $validation['warnings'][] = "Height {$dims['height_m']}m seems unusual";
            }
        }
        
        // Validate brand exists in database
        if (!empty($data['brand'])) {
            $brands = $this->getAllBrands();
            $brandExists = $brands->contains(function($brand) use ($data) {
                return stripos($brand, $data['brand']) !== false || 
                       stripos($data['brand'], $brand) !== false;
            });
            
            if (!$brandExists) {
                $validation['warnings'][] = "Brand '{$data['brand']}' not found in database";
            }
        }
        
        return $validation;
    }
    
    /**
     * Enrich vehicle data with database specifications
     */
    public function enrichVehicleData(array $data): array
    {
        $vehicle = $this->findVehicle($data);
        
        if (!$vehicle) {
            return $data;
        }
        
        // Merge database data (preserve extracted data where available)
        $enriched = array_merge([
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'variant' => $vehicle->variant,
            'year' => $vehicle->year,
            'length_m' => $vehicle->length_m,
            'width_m' => $vehicle->width_m,
            'height_m' => $vehicle->height_m,
            'wheelbase_m' => $vehicle->wheelbase_m,
            'weight_kg' => $vehicle->weight_kg,
            'engine_cc' => $vehicle->engine_cc,
            'fuel_type' => $vehicle->fuel_type,
        ], $data);
        
        // Add database metadata
        $enriched['database_id'] = $vehicle->id;
        $enriched['database_match'] = true;
        $enriched['database_source'] = 'vehicle_specs';
        
        // Add WMI data if available
        if ($vehicle->vinWmi) {
            $enriched['wmi_data'] = [
                'wmi' => $vehicle->vinWmi->wmi,
                'manufacturer' => $vehicle->vinWmi->manufacturer,
                'country' => $vehicle->vinWmi->country,
                'country_code' => $vehicle->vinWmi->country_code
            ];
        }
        
        return $enriched;
    }
}

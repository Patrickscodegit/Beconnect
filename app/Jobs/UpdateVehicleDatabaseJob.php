<?php

namespace App\Jobs;

use App\Models\VehicleSpec;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class UpdateVehicleDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting vehicle database maintenance job');

        try {
            // 1. Check for missing luxury vehicle data
            $this->checkMissingLuxuryVehicles();
            
            // 2. Validate existing data
            $this->validateExistingData();
            
            // 3. Clear stale cache entries
            $this->clearStaleCache();
            
            // 4. Generate statistics
            $this->generateStatistics();
            
            Log::info('Vehicle database maintenance job completed successfully');
            
        } catch (\Exception $e) {
            Log::error('Vehicle database maintenance job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Check for missing luxury vehicle data
     */
    private function checkMissingLuxuryVehicles(): void
    {
        $luxuryMakes = ['BMW', 'Mercedes-Benz', 'Audi', 'Lexus', 'Porsche'];
        $luxuryModels = ['7 Series', 'S-Class', 'A8', 'LS', 'Panamera'];
        
        $missing = [];
        
        foreach ($luxuryMakes as $make) {
            foreach ($luxuryModels as $model) {
                $count = VehicleSpec::where('make', $make)
                    ->where('model', $model)
                    ->count();
                
                if ($count === 0) {
                    $missing[] = "{$make} {$model}";
                }
            }
        }
        
        if (!empty($missing)) {
            Log::warning('Missing luxury vehicle data detected', [
                'missing_vehicles' => $missing,
                'action_required' => 'Consider adding these vehicles to the database'
            ]);
        } else {
            Log::info('All luxury vehicle data is present');
        }
    }

    /**
     * Validate existing vehicle data
     */
    private function validateExistingData(): void
    {
        $invalidVehicles = VehicleSpec::where(function ($query) {
            $query->where('length_m', '<', 2.0)
                  ->orWhere('length_m', '>', 8.0)
                  ->orWhere('width_m', '<', 1.0)
                  ->orWhere('width_m', '>', 3.0)
                  ->orWhere('height_m', '<', 1.0)
                  ->orWhere('height_m', '>', 3.0);
        })->get();
        
        if ($invalidVehicles->count() > 0) {
            Log::warning('Invalid vehicle dimensions detected', [
                'count' => $invalidVehicles->count(),
                'vehicles' => $invalidVehicles->map(function ($v) {
                    return [
                        'id' => $v->id,
                        'make' => $v->make,
                        'model' => $v->model,
                        'year' => $v->year,
                        'dimensions' => "{$v->length_m} x {$v->width_m} x {$v->height_m} m"
                    ];
                })->toArray()
            ]);
        } else {
            Log::info('All vehicle dimensions are valid');
        }
    }

    /**
     * Clear stale cache entries
     */
    private function clearStaleCache(): void
    {
        $cacheKeys = Cache::get('vehicle_dimension_cache_keys', []);
        $cleared = 0;
        
        foreach ($cacheKeys as $key) {
            if (Cache::has($key)) {
                Cache::forget($key);
                $cleared++;
            }
        }
        
        // Clear the cache keys list
        Cache::forget('vehicle_dimension_cache_keys');
        
        Log::info('Cleared stale vehicle dimension cache entries', [
            'cleared_count' => $cleared,
            'total_keys' => count($cacheKeys)
        ]);
    }

    /**
     * Generate statistics about the vehicle database
     */
    private function generateStatistics(): void
    {
        $stats = [
            'total_vehicles' => VehicleSpec::count(),
            'by_make' => VehicleSpec::selectRaw('make, COUNT(*) as count')
                ->groupBy('make')
                ->orderBy('count', 'desc')
                ->get()
                ->toArray(),
            'by_year_range' => [
                'before_2000' => VehicleSpec::where('year', '<', 2000)->count(),
                '2000_2010' => VehicleSpec::whereBetween('year', [2000, 2010])->count(),
                '2010_2020' => VehicleSpec::whereBetween('year', [2010, 2020])->count(),
                'after_2020' => VehicleSpec::where('year', '>', 2020)->count(),
            ],
            'luxury_vehicles' => VehicleSpec::whereIn('make', ['BMW', 'Mercedes-Benz', 'Audi', 'Lexus', 'Porsche'])
                ->count(),
            'average_dimensions' => [
                'length' => VehicleSpec::avg('length_m'),
                'width' => VehicleSpec::avg('width_m'),
                'height' => VehicleSpec::avg('height_m'),
            ]
        ];
        
        Log::info('Vehicle database statistics', $stats);
        
        // Store stats in cache for dashboard
        Cache::put('vehicle_database_stats', $stats, 3600); // 1 hour
    }
}

<?php

namespace App\Console\Commands;

use App\Models\VehicleSpec;
use App\Models\VinWmi;
use Illuminate\Console\Command;

class UpdateVehiclesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vehicles:update {--force : Force update existing records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update VIN WMI and vehicle specifications from CSV files';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting vehicle data update...');
        
        $force = $this->option('force');
        
        if ($force) {
            $this->warn('Force mode enabled - existing records will be overwritten');
        }

        // Update VIN WMIs
        $this->updateVinWmis($force);
        
        // Update Vehicle Specs
        $this->updateVehicleSpecs($force);
        
        $this->info('Vehicle data update completed successfully!');
        
        return Command::SUCCESS;
    }

    private function updateVinWmis(bool $force): void
    {
        $csvPath = database_path('seeders/data/vin_wmis.csv');
        
        if (!file_exists($csvPath)) {
            $this->warn("VIN WMI CSV file not found: {$csvPath}");
            return;
        }

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            $this->error("Could not open VIN WMI CSV file");
            return;
        }

        // Skip header
        fgetcsv($handle);
        
        $updated = 0;
        $created = 0;
        
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 7) continue;
            
            $wmi = VinWmi::where('wmi', $data[0])->first();
            
            if ($wmi && !$force) {
                continue; // Skip existing unless force mode
            }
            
            $record = VinWmi::updateOrCreate(
                ['wmi' => $data[0]],
                [
                    'manufacturer' => $data[1],
                    'country' => $data[2],
                    'country_code' => $data[3],
                    'start_year' => (int) $data[4],
                    'end_year' => !empty($data[5]) ? (int) $data[5] : null,
                    'verified_at' => $data[6],
                    'verified_by' => $data[7] ?? 'System',
                ]
            );
            
            if ($record->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        fclose($handle);
        
        $this->info("VIN WMI - Created: {$created}, Updated: {$updated}");
    }

    private function updateVehicleSpecs(bool $force): void
    {
        $csvPath = database_path('seeders/data/vehicle_specs.csv');
        
        if (!file_exists($csvPath)) {
            $this->warn("Vehicle specs CSV file not found: {$csvPath}");
            return;
        }

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            $this->error("Could not open vehicle specs CSV file");
            return;
        }

        // Skip header
        fgetcsv($handle);
        
        $updated = 0;
        $created = 0;
        $skipped = 0;
        
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 11) continue;
            
            $wmi = VinWmi::where('manufacturer', $data[0])
                         ->where('start_year', '<=', (int) $data[3])
                         ->where(function ($query) use ($data) {
                             $query->whereNull('end_year')
                                   ->orWhere('end_year', '>=', (int) $data[3]);
                         })
                         ->first();

            if (!$wmi) {
                $skipped++;
                continue;
            }
            
            $existing = VehicleSpec::where('make', $data[0])
                                  ->where('model', $data[1])
                                  ->where('year', (int) $data[3])
                                  ->where('variant', $data[2] ?: null)
                                  ->first();
            
            if ($existing && !$force) {
                continue;
            }
            
            $record = VehicleSpec::updateOrCreate(
                [
                    'make' => $data[0],
                    'model' => $data[1],
                    'variant' => !empty($data[2]) ? $data[2] : null,
                    'year' => (int) $data[3],
                ],
                [
                    'length_m' => (float) $data[4],
                    'width_m' => (float) $data[5],
                    'height_m' => (float) $data[6],
                    'wheelbase_m' => (float) $data[7],
                    'weight_kg' => (int) $data[8],
                    'engine_cc' => (int) $data[9],
                    'fuel_type' => $data[10],
                    'wmi_id' => $wmi->id,
                ]
            );
            
            if ($record->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        fclose($handle);
        
        $this->info("Vehicle Specs - Created: {$created}, Updated: {$updated}, Skipped: {$skipped}");
    }
}

<?php

namespace Database\Seeders;

use App\Models\VehicleSpec;
use App\Models\VinWmi;
use Illuminate\Database\Seeder;

class VehicleSpecsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvPath = database_path('seeders/data/vehicle_specs.csv');
        
        if (!file_exists($csvPath)) {
            $this->command->warn("CSV file not found: {$csvPath}");
            return;
        }

        $handle = fopen($csvPath, 'r');
        
        if ($handle === false) {
            $this->command->error("Could not open CSV file: {$csvPath}");
            return;
        }

        // Skip header row
        fgetcsv($handle);
        
        $count = 0;
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 11) {
                continue;
            }

            // Map CSV columns to our expected structure:
            // 0:make, 1:model, 2:generation(variant), 3:year, 4:body_style, 
            // 5:length_m, 6:width_m, 7:height_m, 8:wheelbase_m, 9:weight_kg, 
            // 10:engine_cc, 11:fuel_type, 12:powertrain_type, 13:country_of_manufacture,
            // 14:weight_type, 15:width_basis, 16:verified_at, 17:verified_by, 18:wmi_hint

            // Find WMI by manufacturer and year, or use wmi_hint if provided
            $wmi = null;
            if (!empty($data[18])) {
                // Try to find WMI by the hint first
                $wmi = VinWmi::where('wmi', $data[18])->first();
            }
            
            if (!$wmi) {
                // Fallback to manufacturer matching
                $wmi = VinWmi::where('manufacturer', 'like', '%' . $data[0] . '%')
                             ->where('start_year', '<=', (int) $data[3])
                             ->where(function ($query) use ($data) {
                                 $query->whereNull('end_year')
                                       ->orWhere('end_year', '>=', (int) $data[3]);
                             })
                             ->first();
            }

            if (!$wmi) {
                $this->command->warn("No matching WMI found for {$data[0]} in year {$data[3]} (hint: {$data[18]})");
                continue;
            }

            VehicleSpec::firstOrCreate(
                [
                    'make' => $data[0],
                    'model' => $data[1],
                    'year' => (int) $data[3],
                    'variant' => !empty($data[2]) ? $data[2] : null,
                ],
                [
                    'length_m' => (float) $data[5],
                    'width_m' => (float) $data[6],
                    'height_m' => (float) $data[7],
                    'wheelbase_m' => (float) $data[8],
                    'weight_kg' => (int) $data[9],
                    'engine_cc' => (int) $data[10],
                    'fuel_type' => strtolower($data[11]), // Convert to lowercase to match our enum
                    'wmi_id' => $wmi->id,
                ]
            );
            $count++;
        }

        fclose($handle);
        
        $this->command->info("Imported {$count} vehicle specification records");
    }
}

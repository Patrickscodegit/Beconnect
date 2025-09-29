<?php

namespace Database\Seeders;

use App\Models\VehicleSpec;
use App\Models\VinWmi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class PopularLuxuryVehiclesSeeder extends Seeder
{
    public function run(): void
    {
        $csvFile = database_path('seeders/data/popular_luxury_vehicles.csv');
        
        if (!file_exists($csvFile)) {
            Log::error('Popular luxury vehicles CSV file not found: ' . $csvFile);
            return;
        }

        $handle = fopen($csvFile, 'r');
        if (!$handle) {
            Log::error('Could not open popular luxury vehicles CSV file: ' . $csvFile);
            return;
        }

        // Skip header row
        fgetcsv($handle);

        $imported = 0;
        $skipped = 0;

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 19) {
                $skipped++;
                continue;
            }

            // Find or create WMI
            $wmi = VinWmi::where('wmi', $data[18])->first();
            if (!$wmi) {
                Log::warning('WMI not found for popular luxury vehicle: ' . $data[18]);
                $skipped++;
                continue;
            }

            // Create vehicle spec using firstOrCreate to prevent duplicates
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
            $imported++;
        }

        fclose($handle);

        Log::info("Popular luxury vehicles seeding completed: {$imported} imported, {$skipped} skipped");
        echo "Imported {$imported} popular luxury vehicle specification records\n";
    }
}

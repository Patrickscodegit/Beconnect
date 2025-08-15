<?php

namespace Database\Seeders;

use App\Models\VinWmi;
use Illuminate\Database\Seeder;

class VinWmiSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvPath = database_path('seeders/data/vin_wmis.csv');
        
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
            if (count($data) < 7) {
                continue;
            }

            VinWmi::updateOrCreate(
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
            $count++;
        }

        fclose($handle);
        
        $this->command->info("Imported {$count} VIN WMI records");
    }
}

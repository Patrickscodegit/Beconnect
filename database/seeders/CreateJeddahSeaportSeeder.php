<?php

namespace Database\Seeders;

use App\Models\Port;
use Illuminate\Database\Seeder;

class CreateJeddahSeaportSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * Creates/updates Jeddah airport (JED) and creates Jeddah seaport (SAJED-SEA).
     * Both facilities share the same UN/LOCODE (SAJED) and city_unlocode.
     */
    public function run(): void
    {
        $this->command->info('Creating/updating Jeddah facilities...');

        // Ensure/Update airport port
        $airport = Port::updateOrCreate(
            ['code' => 'JED'],
            [
                'name' => 'Jeddah',
                'country' => 'Saudi Arabia',
                'country_code' => 'SA',
                'region' => 'Middle East',
                'port_category' => 'AIRPORT',
                'unlocode' => 'SAJED',
                'city_unlocode' => 'SAJED',
                'iata_code' => 'JED',
                'icao_code' => 'OEJN',
                'is_active' => true,
            ]
        );

        $this->command->info("✓ Airport port: {$airport->code} - {$airport->name}");

        // Create seaport facility
        $seaport = Port::updateOrCreate(
            ['code' => 'SAJED-SEA'],
            [
                'name' => 'Jeddah',
                'country' => 'Saudi Arabia',
                'country_code' => 'SA',
                'region' => 'Middle East',
                'port_category' => 'SEA_PORT',
                'unlocode' => 'SAJED',
                'city_unlocode' => 'SAJED',
                'iata_code' => null,
                'icao_code' => null,
                'is_active' => true,
            ]
        );

        $this->command->info("✓ Seaport facility: {$seaport->code} - {$seaport->name}");

        $this->command->info('Jeddah facilities created/updated successfully.');
        $this->command->info("  - Airport: {$airport->code} (IATA: {$airport->iata_code}, ICAO: {$airport->icao_code})");
        $this->command->info("  - Seaport: {$seaport->code}");
        $this->command->info("  - Both share city_unlocode: SAJED");
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Port;

class OfficialUnlocodeSeeder extends Seeder
{
    /**
     * Seed ports using official UN/LOCODE data
     * This replaces manual port definitions with verified UN/LOCODE data
     * 
     * Source: Official UN/LOCODE database (manually verified)
     * Format: 5-character codes (2-letter country + 3-letter location)
     */
    public function run(): void
    {
        $this->command->info('🚢 Seeding ports with official UN/LOCODE data...');
        $this->command->newLine();

        $ports = [
            // West Africa (8 ports)
            [
                'code' => 'ABJ',
                'name' => 'Abidjan',
                'country' => 'Côte d\'Ivoire',
                'region' => 'West Africa',
                'unlocode' => 'CI ABJ',
                'coordinates' => '5.3167,-4.0333',
                'type' => 'pod'
            ],
            [
                'code' => 'CKY',
                'name' => 'Conakry',
                'country' => 'Guinea',
                'region' => 'West Africa',
                'unlocode' => 'GN CKY',
                'coordinates' => '9.6412,-13.5784',
                'type' => 'pod'
            ],
            [
                'code' => 'COO',
                'name' => 'Cotonou',
                'country' => 'Benin',
                'region' => 'West Africa',
                'unlocode' => 'BJ COO',
                'coordinates' => '6.3667,2.4333',
                'type' => 'pod'
            ],
            [
                'code' => 'DKR',
                'name' => 'Dakar',
                'country' => 'Senegal',
                'region' => 'West Africa',
                'unlocode' => 'SN DKR',
                'coordinates' => '14.6928,-17.4467',
                'type' => 'pod'
            ],
            [
                'code' => 'DLA',
                'name' => 'Douala',
                'country' => 'Cameroon',
                'region' => 'West Africa',
                'unlocode' => 'CM DLA',
                'coordinates' => '4.0500,9.7000',
                'type' => 'pod'
            ],
            [
                'code' => 'LOS',
                'name' => 'Lagos',
                'country' => 'Nigeria',
                'region' => 'West Africa',
                'unlocode' => 'NG LOS',
                'coordinates' => '6.5244,3.3792',
                'type' => 'pod'
            ],
            [
                'code' => 'LFW',
                'name' => 'Lomé',
                'country' => 'Togo',
                'region' => 'West Africa',
                'unlocode' => 'TG LFW',
                'coordinates' => '6.1319,1.2228',
                'type' => 'pod'
            ],
            [
                'code' => 'PNR',
                'name' => 'Pointe Noire',
                'country' => 'Republic of Congo',
                'region' => 'West Africa',
                'unlocode' => 'CG PNR',
                'coordinates' => '-4.7761,11.8636',
                'type' => 'pod'
            ],
            
            // East Africa (2 ports)
            [
                'code' => 'DAR',
                'name' => 'Dar es Salaam',
                'country' => 'Tanzania',
                'region' => 'East Africa',
                'unlocode' => 'TZ DAR',
                'coordinates' => '-6.7924,39.2083',
                'type' => 'pod'
            ],
            [
                'code' => 'MBA',
                'name' => 'Mombasa',
                'country' => 'Kenya',
                'region' => 'East Africa',
                'unlocode' => 'KE MBA',
                'coordinates' => '-4.0437,39.6682',
                'type' => 'pod'
            ],
            
            // South Africa (4 ports)
            [
                'code' => 'DUR',
                'name' => 'Durban',
                'country' => 'South Africa',
                'region' => 'South Africa',
                'unlocode' => 'ZA DUR',
                'coordinates' => '-29.8587,31.0218',
                'type' => 'pod'
            ],
            [
                'code' => 'ELS',
                'name' => 'East London',
                'country' => 'South Africa',
                'region' => 'South Africa',
                'unlocode' => 'ZA ELS',
                'coordinates' => '-33.0292,27.8546',
                'type' => 'pod'
            ],
            [
                'code' => 'PLZ',
                'name' => 'Port Elizabeth',
                'country' => 'South Africa',
                'region' => 'South Africa',
                'unlocode' => 'ZA PLZ',
                'coordinates' => '-33.9608,25.6022',
                'type' => 'pod'
            ],
            [
                'code' => 'WVB',
                'name' => 'Walvis Bay',
                'country' => 'Namibia',
                'region' => 'South Africa',
                'unlocode' => 'NA WVB',
                'coordinates' => '-22.9576,14.5053',
                'type' => 'pod'
            ],
            
            // Europe (3 POLs)
            [
                'code' => 'ANR',
                'name' => 'Antwerp',
                'country' => 'Belgium',
                'region' => 'Europe',
                'unlocode' => 'BE ANR',
                'coordinates' => '51.2194,4.4025',
                'type' => 'pol'
            ],
            [
                'code' => 'ZEE',
                'name' => 'Zeebrugge',
                'country' => 'Belgium',
                'region' => 'Europe',
                'unlocode' => 'BE ZEE',
                'coordinates' => '51.3308,3.2075',
                'type' => 'pol'
            ],
            [
                'code' => 'FLU',
                'name' => 'Vlissingen',
                'country' => 'Netherlands',
                'region' => 'Europe',
                'unlocode' => 'NL VLI',
                'coordinates' => '51.4425,3.5736',
                'type' => 'pol'
            ],
        ];

        $imported = 0;
        $updated = 0;

        foreach ($ports as $portData) {
            $existingPort = Port::where('code', $portData['code'])->first();
            
            if ($existingPort) {
                // Update existing port with official UN/LOCODE data
                $existingPort->update([
                    'name' => $portData['name'],
                    'country' => $portData['country'],
                    'region' => $portData['region'],
                    'coordinates' => $portData['coordinates'],
                    'type' => $portData['type'],
                    'is_active' => true
                ]);
                $updated++;
                $this->command->line("🔄 Updated: {$portData['code']} - {$portData['name']} ({$portData['unlocode']})");
            } else {
                // Create new port with official UN/LOCODE data
                Port::create([
                    'code' => $portData['code'],
                    'name' => $portData['name'],
                    'country' => $portData['country'],
                    'region' => $portData['region'],
                    'coordinates' => $portData['coordinates'],
                    'type' => $portData['type'],
                    'is_active' => true
                ]);
                $imported++;
                $this->command->line("➕ Imported: {$portData['code']} - {$portData['name']} ({$portData['unlocode']})");
            }
        }

        $this->command->newLine();
        $this->command->info("✅ Official UN/LOCODE seeding completed!");
        $this->command->info("   Imported: {$imported} ports");
        $this->command->info("   Updated: {$updated} ports");
        $this->command->info("   Total ports: " . Port::count());
        
        // Display summary by region
        $this->command->newLine();
        $this->command->info("📊 Ports by Region:");
        $regions = Port::selectRaw('region, type, COUNT(*) as count')
            ->groupBy('region', 'type')
            ->orderBy('region')
            ->get();
            
        foreach ($regions as $region) {
            $this->command->line("   {$region->region} ({$region->type}): {$region->count} ports");
        }
    }
}

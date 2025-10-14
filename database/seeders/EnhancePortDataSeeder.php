<?php

namespace Database\Seeders;

use App\Models\Port;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class EnhancePortDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Enhancing port data with shipping codes and regional flags...');

        // European Origins (POL) - The 3 main loading ports
        $europeanOrigins = [
            [
                'code' => 'ANR',
                'shipping_codes' => ['ANR', 'ANTW', 'ANT'],
                'country' => 'Belgium',
            ],
            [
                'code' => 'ZEE',
                'shipping_codes' => ['ZEE', 'ZBR'],
                'country' => 'Belgium',
            ],
            [
                'code' => 'FLU',
                'shipping_codes' => ['FLU', 'VLI'],
                'country' => 'Netherlands',
            ],
        ];

        foreach ($europeanOrigins as $portData) {
            $port = Port::where('code', $portData['code'])->first();
            if ($port) {
                $port->update([
                    'shipping_codes' => $portData['shipping_codes'],
                    'country' => $portData['country'],
                    'is_european_origin' => true,
                    'port_type' => 'pol',
                ]);
                $this->command->info("✓ Updated {$port->name} as European origin (POL)");
            } else {
                $this->command->warn("⚠ Port {$portData['code']} not found");
            }
        }

        // African Destinations (POD) - Common destination ports
        $africanDestinations = [
            'LOS' => ['shipping_codes' => ['LOS', 'LAGOS'], 'country' => 'Nigeria'],
            'DAR' => ['shipping_codes' => ['DAR', 'DSM'], 'country' => 'Tanzania'],
            'MBA' => ['shipping_codes' => ['MBA', 'MBS'], 'country' => 'Kenya'],
            'DUR' => ['shipping_codes' => ['DUR', 'DBN'], 'country' => 'South Africa'],
            'ABJ' => ['shipping_codes' => ['ABJ'], 'country' => 'Ivory Coast'],
            'ACC' => ['shipping_codes' => ['ACC'], 'country' => 'Ghana'],
            'APL' => ['shipping_codes' => ['APL'], 'country' => 'Mozambique'],
            'BGF' => ['shipping_codes' => ['BGF'], 'country' => 'Central African Republic'],
            'BJL' => ['shipping_codes' => ['BJL'], 'country' => 'Gambia'],
            'BKO' => ['shipping_codes' => ['BKO'], 'country' => 'Mali'],
            'BZV' => ['shipping_codes' => ['BZV'], 'country' => 'Congo'],
            'CKY' => ['shipping_codes' => ['CKY'], 'country' => 'Guinea'],
            'CMN' => ['shipping_codes' => ['CMN'], 'country' => 'Morocco'],
            'COO' => ['shipping_codes' => ['COO'], 'country' => 'Benin'],
            'CPT' => ['shipping_codes' => ['CPT'], 'country' => 'South Africa'],
            'DKR' => ['shipping_codes' => ['DKR'], 'country' => 'Senegal'],
            'DLA' => ['shipping_codes' => ['DLA'], 'country' => 'Cameroon'],
            'FIH' => ['shipping_codes' => ['FIH'], 'country' => 'Democratic Republic of Congo'],
            'FNA' => ['shipping_codes' => ['FNA'], 'country' => 'Sierra Leone'],
            'JIB' => ['shipping_codes' => ['JIB'], 'country' => 'Djibouti'],
            'LFW' => ['shipping_codes' => ['LFW'], 'country' => 'Togo'],
            'LBV' => ['shipping_codes' => ['LBV'], 'country' => 'Gabon'],
            'MPM' => ['shipping_codes' => ['MPM'], 'country' => 'Mozambique'],
            'NBO' => ['shipping_codes' => ['NBO'], 'country' => 'Kenya'],
            'NDB' => ['shipping_codes' => ['NDB'], 'country' => 'Mauritania'],
            'NKC' => ['shipping_codes' => ['NKC'], 'country' => 'Mauritania'],
            'PLZ' => ['shipping_codes' => ['PLZ'], 'country' => 'South Africa'],
            'PNR' => ['shipping_codes' => ['PNR'], 'country' => 'Congo'],
            'RAB' => ['shipping_codes' => ['RAB'], 'country' => 'Morocco'],
            'TMS' => ['shipping_codes' => ['TMS'], 'country' => 'Mauritania'],
            'TNR' => ['shipping_codes' => ['TNR'], 'country' => 'Madagascar'],
            'TUN' => ['shipping_codes' => ['TUN'], 'country' => 'Tunisia'],
            'WDH' => ['shipping_codes' => ['WDH'], 'country' => 'Namibia'],
        ];

        foreach ($africanDestinations as $code => $data) {
            $port = Port::where('code', $code)->first();
            if ($port) {
                $port->update([
                    'shipping_codes' => $data['shipping_codes'],
                    'country' => $data['country'],
                    'is_african_destination' => true,
                    'port_type' => 'both', // Can serve as both POL and POD
                ]);
                $this->command->info("✓ Updated {$port->name} as African destination");
            }
        }

        // Update remaining ports to have 'both' as default
        Port::whereNull('port_type')
            ->orWhere('port_type', '')
            ->update(['port_type' => 'both']);

        $this->command->info('✅ Port data enhancement complete!');
        $this->command->info('European origins (POL): ' . Port::where('is_european_origin', true)->count());
        $this->command->info('African destinations: ' . Port::where('is_african_destination', true)->count());
    }
}

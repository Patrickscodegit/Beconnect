<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Port;

class SallaumPortsSeeder extends Seeder
{
    /**
     * Seed POD ports for Sallaum Lines routes
     * 
     * Sallaum Lines specializes in West Africa routes
     * Main destinations from Europe (Antwerp/Zeebrugge/Flushing) to West Africa
     */
    public function run(): void
    {
        $sallaumPods = [
            // West Africa - Major ports
            ['code' => 'LOS', 'name' => 'Lagos (Tin Can Island)', 'country' => 'Nigeria', 'region' => 'West Africa', 'type' => 'pod'],
            ['code' => 'TIN', 'name' => 'Tin Can Island', 'country' => 'Nigeria', 'region' => 'West Africa', 'type' => 'pod'],
            ['code' => 'APP', 'name' => 'Apapa', 'country' => 'Nigeria', 'region' => 'West Africa', 'type' => 'pod'],
            ['code' => 'DKR', 'name' => 'Dakar', 'country' => 'Senegal', 'region' => 'West Africa', 'type' => 'pod'],
            ['code' => 'ABJ', 'name' => 'Abidjan', 'country' => 'Côte d\'Ivoire', 'region' => 'West Africa', 'type' => 'pod'],
            ['code' => 'TEM', 'name' => 'Tema', 'country' => 'Ghana', 'region' => 'West Africa', 'type' => 'pod'],
            ['code' => 'CKY', 'name' => 'Conakry', 'country' => 'Guinea', 'region' => 'West Africa', 'type' => 'pod'],
            ['code' => 'LFW', 'name' => 'Lomé', 'country' => 'Togo', 'region' => 'West Africa', 'type' => 'pod'],
            ['code' => 'COO', 'name' => 'Cotonou', 'country' => 'Benin', 'region' => 'West Africa', 'type' => 'pod'],
        ];

        foreach ($sallaumPods as $portData) {
            Port::updateOrCreate(
                ['code' => $portData['code']],
                $portData
            );
        }

        $this->command->info('Sallaum POD ports seeded: ' . count($sallaumPods) . ' West African ports');
    }
}


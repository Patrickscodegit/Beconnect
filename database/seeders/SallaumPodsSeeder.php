<?php

namespace Database\Seeders;

use App\Models\Port;
use Illuminate\Database\Seeder;

class SallaumPodsSeeder extends Seeder
{
    /**
     * Seed Sallaum Lines POD ports (destinations) from their website
     * POLs remain: Antwerp, Zeebrugge, Flushing (as requested)
     * 
     * Source: https://sallaumlines.com/schedules/europe-to-west-and-south-africa/
     */
    public function run(): void
    {
        $pods = [
            // West Africa
            ['code' => 'ABJ', 'name' => 'Abidjan', 'country' => 'Côte d\'Ivoire', 'type' => 'pod'],
            ['code' => 'CKY', 'name' => 'Conakry', 'country' => 'Guinea', 'type' => 'pod'], // Already exists, will update
            ['code' => 'COO', 'name' => 'Cotonou', 'country' => 'Benin', 'type' => 'pod'], // Already exists, will update
            ['code' => 'DKR', 'name' => 'Dakar', 'country' => 'Senegal', 'type' => 'pod'], // Already exists, will update
            ['code' => 'DLA', 'name' => 'Douala', 'country' => 'Cameroon', 'type' => 'pod'],
            ['code' => 'LOS', 'name' => 'Lagos (Tin Can Island)', 'country' => 'Nigeria', 'type' => 'pod'], // Already exists, will update
            ['code' => 'LFW', 'name' => 'Lomé', 'country' => 'Togo', 'type' => 'pod'], // Already exists, will update
            ['code' => 'PNR', 'name' => 'Pointe Noire', 'country' => 'Republic of Congo', 'type' => 'pod'],
            
            // East Africa
            ['code' => 'DAR', 'name' => 'Dar es Salaam', 'country' => 'Tanzania', 'type' => 'pod'],
            ['code' => 'MBA', 'name' => 'Mombasa', 'country' => 'Kenya', 'type' => 'pod'],
            
            // South Africa
            ['code' => 'DUR', 'name' => 'Durban', 'country' => 'South Africa', 'type' => 'pod'],
            ['code' => 'ELS', 'name' => 'East London', 'country' => 'South Africa', 'type' => 'pod'],
            ['code' => 'PLZ', 'name' => 'Port Elizabeth', 'country' => 'South Africa', 'type' => 'pod'],
            ['code' => 'WVB', 'name' => 'Walvis Bay', 'country' => 'Namibia', 'type' => 'pod'],
        ];

        foreach ($pods as $portData) {
            Port::updateOrCreate(
                ['code' => $portData['code']],
                $portData
            );
            
            $this->command->info("✓ Port: {$portData['name']} ({$portData['code']})");
        }

        $this->command->info("\n✅ Sallaum Lines POD ports seeded successfully!");
        $this->command->info("Total POD ports available: " . Port::whereIn('type', ['pod', 'both'])->count());
    }
}


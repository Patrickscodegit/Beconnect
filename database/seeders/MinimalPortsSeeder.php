<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Port;

class MinimalPortsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Only seed the 3 required POLs - NO PODs yet
        // PODs will be added one by one as we implement real carriers
        $ports = [
            ['code' => 'ANR', 'name' => 'Antwerp', 'country' => 'Belgium', 'region' => 'Europe'],
            ['code' => 'ZEE', 'name' => 'Zeebrugge', 'country' => 'Belgium', 'region' => 'Europe'],
            ['code' => 'FLU', 'name' => 'Flushing', 'country' => 'Netherlands', 'region' => 'Europe'],
        ];

        foreach ($ports as $portData) {
            Port::updateOrCreate(
                ['code' => $portData['code']],
                $portData
            );
        }

        $this->command->info('Minimal ports seeded: ' . Port::count() . ' ports (3 POLs only, no PODs yet)');
    }
}

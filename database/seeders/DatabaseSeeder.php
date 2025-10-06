<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            FilamentUserSeeder::class,
            VinWmiSeeder::class,
            VehicleSpecsSeeder::class,
            PopularLuxuryVehiclesSeeder::class,
            // Schedule system seeders
            ShippingCarrierSeeder::class,
            PortSeeder::class,
        ]);
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Port;
use App\Models\ShippingCarrier;

class SeedScheduleData extends Command
{
    protected $signature = 'schedules:seed-data';
    protected $description = 'Seed ports and carriers data for the schedules system';

    public function handle()
    {
        $this->info('Seeding schedule system data...');

        // Check if ports exist
        $portCount = Port::count();
        if ($portCount === 0) {
            $this->info('No ports found. Running PortSeeder...');
            $this->call('db:seed', ['--class' => 'PortSeeder']);
            $this->info('✓ Ports seeded successfully');
        } else {
            $this->info("✓ Ports already exist ({$portCount} ports)");
        }

        // Check if carriers exist
        $carrierCount = ShippingCarrier::count();
        if ($carrierCount === 0) {
            $this->info('No carriers found. Running ShippingCarrierSeeder...');
            $this->call('db:seed', ['--class' => 'ShippingCarrierSeeder']);
            $this->info('✓ Carriers seeded successfully');
        } else {
            $this->info("✓ Carriers already exist ({$carrierCount} carriers)");
        }

        $this->info('Schedule system data seeding completed!');
        return 0;
    }
}

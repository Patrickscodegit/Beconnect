<?php

namespace App\Console\Commands;

use App\Models\ShippingSchedule;
use App\Models\ShippingCarrier;
use Illuminate\Console\Command;

class CleanOldSallaumSchedules extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schedules:clean-old-sallaum';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean old/invalid Sallaum schedules from database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🧹 Cleaning old Sallaum schedules...');
        
        $carrier = ShippingCarrier::where('name', 'like', '%Sallaum%')->first();
        
        if (!$carrier) {
            $this->error('❌ Sallaum carrier not found in database!');
            return 1;
        }

        $this->info("Found carrier: {$carrier->name} (ID: {$carrier->id})");

        // Step 1: Delete schedules with past sailing dates
        $deletedPast = ShippingSchedule::where('carrier_id', $carrier->id)
            ->whereDate('next_sailing_date', '<', now())
            ->delete();
        
        $this->info("✅ Deleted {$deletedPast} past Sallaum schedules (sailing date < today)");

        // Step 2: Delete schedules that haven't been updated recently (likely stale)
        $cutoffDate = '2025-10-23'; // Before today's fix
        $deletedOld = ShippingSchedule::where('carrier_id', $carrier->id)
            ->where('last_updated', '<', $cutoffDate)
            ->delete();
        
        $this->info("✅ Deleted {$deletedOld} outdated Sallaum schedules (last_updated < {$cutoffDate})");
        
        // Step 3: Show what's left
        $remaining = ShippingSchedule::where('carrier_id', $carrier->id)->count();
        $future = ShippingSchedule::where('carrier_id', $carrier->id)
            ->whereDate('next_sailing_date', '>', now())
            ->count();
        
        $this->info("📊 Remaining schedules: {$remaining} total, {$future} future sailings");
        
        if ($future > 0) {
            $this->info('');
            $this->info('Sample of remaining future schedules:');
            $samples = ShippingSchedule::where('carrier_id', $carrier->id)
                ->whereDate('next_sailing_date', '>', now())
                ->with(['polPort', 'podPort'])
                ->orderBy('next_sailing_date')
                ->limit(5)
                ->get();
            
            foreach ($samples as $schedule) {
                $this->line("  - {$schedule->vessel_name} | {$schedule->polPort->name} → {$schedule->podPort->name} | {$schedule->next_sailing_date->format('Y-m-d')}");
            }
        }
        
        $this->info('');
        $this->info('✨ Cleanup completed successfully!');
        
        return 0;
    }
}

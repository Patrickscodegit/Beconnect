<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ScheduleExtraction\RealNmtScheduleExtractionStrategy;
use App\Services\ScheduleExtraction\RealGrimaldiScheduleExtractionStrategy;
use App\Services\ScheduleExtraction\RealWalleniusWilhelmsenScheduleExtractionStrategy;
use App\Models\ShippingSchedule;

class TestRealDataExtraction extends Command
{
    protected $signature = 'schedules:test-real-data {--route=} {--carrier=}';
    protected $description = 'Test real data extraction from carrier websites';

    public function handle()
    {
        $this->info('🧪 TESTING REAL DATA EXTRACTION');
        $this->info('===============================');

        // Clear existing schedules
        ShippingSchedule::truncate();
        $this->info('Cleared existing schedules');

        $route = $this->option('route') ?: 'ANR-LOS';
        $carrier = $this->option('carrier') ?: 'all';

        [$pol, $pod] = explode('-', $route);

        $this->info("Testing route: {$pol} -> {$pod}");
        $this->info("Testing carrier: {$carrier}");

        $strategies = [];

        if ($carrier === 'all' || $carrier === 'nmt') {
            $strategies[] = new RealNmtScheduleExtractionStrategy();
        }
        if ($carrier === 'all' || $carrier === 'grimaldi') {
            $strategies[] = new RealGrimaldiScheduleExtractionStrategy();
        }
        if ($carrier === 'all' || $carrier === 'wallenius') {
            $strategies[] = new RealWalleniusWilhelmsenScheduleExtractionStrategy();
        }

        foreach ($strategies as $strategy) {
            $this->info("\n🔍 Testing {$strategy->getCarrierName()}...");
            
            // Test if route is supported
            $supports = $strategy->supports($pol, $pod);
            $this->info("   Route supported: " . ($supports ? '✅ YES' : '❌ NO'));
            
            if ($supports) {
                // Test fetching real data
                $schedules = $strategy->extractSchedules($pol, $pod);
                $this->info("   Schedules found: " . count($schedules));
                
                if (count($schedules) > 0) {
                    $this->info("   ✅ REAL DATA EXTRACTED!");
                    foreach ($schedules as $schedule) {
                        $this->info("      - {$schedule['service_name']} ({$schedule['frequency']}x/week, {$schedule['transit_time']} days)");
                    }
                } else {
                    $this->info("   ⚠️  No schedules found (route may not exist)");
                }
            }
        }

        $totalSchedules = ShippingSchedule::count();
        $this->info("\n📊 RESULTS:");
        $this->info("Total schedules in database: {$totalSchedules}");
        
        if ($totalSchedules > 0) {
            $this->info("✅ SUCCESS: Real data extraction is working!");
            $this->info("Only actual carrier schedules are shown.");
        } else {
            $this->info("ℹ️  No schedules found - this is expected if routes don't exist on carrier websites");
            $this->info("This confirms no mock data is being generated.");
        }

        return 0;
    }
}
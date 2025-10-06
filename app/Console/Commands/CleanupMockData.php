<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ShippingSchedule;
use Illuminate\Support\Facades\File;

class CleanupMockData extends Command
{
    protected $signature = 'schedules:cleanup-mock-data';
    protected $description = 'Clean up all mock data and verify only real data remains';

    public function handle()
    {
        $this->info('🧹 CLEANING UP MOCK DATA');
        $this->info('========================');

        // 1. Clear all schedules from database
        $this->info('1. Clearing all schedules from database...');
        $scheduleCount = ShippingSchedule::count();
        ShippingSchedule::truncate();
        $this->info("   ✅ Cleared {$scheduleCount} schedules");

        // 2. Check for remaining mock data strategy files
        $this->info('2. Checking for remaining mock data strategy files...');
        $strategyPath = app_path('Services/ScheduleExtraction/');
        $strategyFiles = File::files($strategyPath);
        
        $mockStrategies = [];
        $realStrategies = [];
        
        foreach ($strategyFiles as $file) {
            $filename = $file->getFilename();
            if (strpos($filename, 'Real') === 0) {
                $realStrategies[] = $filename;
            } elseif (strpos($filename, 'ScheduleExtractionStrategy.php') !== false) {
                $mockStrategies[] = $filename;
            }
        }
        
        if (count($mockStrategies) > 0) {
            $this->error('   ❌ Found mock data strategies that should be removed:');
            foreach ($mockStrategies as $strategy) {
                $this->error("      - {$strategy}");
            }
        } else {
            $this->info('   ✅ No mock data strategies found');
        }
        
        if (count($realStrategies) > 0) {
            $this->info('   ✅ Real data strategies found:');
            foreach ($realStrategies as $strategy) {
                $this->info("      - {$strategy}");
            }
        }

        // 3. Check job file for mock data references
        $this->info('3. Checking job file for mock data references...');
        $jobFile = app_path('Jobs/UpdateShippingSchedulesJob.php');
        $jobContent = File::get($jobFile);
        
        $mockReferences = [
            'GrimaldiScheduleExtractionStrategy',
            'WalleniusWilhelmsenScheduleExtractionStrategy',
            'HoeghAutolinersScheduleExtractionStrategy',
            'NmtScheduleExtractionStrategy',
            'SallaumScheduleExtractionStrategy',
            'UeccScheduleExtractionStrategy',
            'EukorScheduleExtractionStrategy',
            'NykScheduleExtractionStrategy',
            'MarinvestsScheduleExtractionStrategy',
            'NirintScheduleExtractionStrategy',
            'EclScheduleExtractionStrategy',
            'KlineScheduleExtractionStrategy',
            'MarfretScheduleExtractionStrategy',
            'SeatradeScheduleExtractionStrategy',
            'GeestLineScheduleExtractionStrategy'
        ];
        
        $foundMockReferences = [];
        foreach ($mockReferences as $reference) {
            if (strpos($jobContent, $reference) !== false) {
                $foundMockReferences[] = $reference;
            }
        }
        
        if (count($foundMockReferences) > 0) {
            $this->error('   ❌ Found mock data references in job file:');
            foreach ($foundMockReferences as $reference) {
                $this->error("      - {$reference}");
            }
        } else {
            $this->info('   ✅ No mock data references found in job file');
        }

        // 4. Summary
        $this->info('4. Cleanup Summary:');
        $this->info('   - Database schedules: 0 (all cleared)');
        $this->info('   - Mock strategies: ' . count($mockStrategies) . ' (should be 0)');
        $this->info('   - Real strategies: ' . count($realStrategies));
        $this->info('   - Mock references in job: ' . count($foundMockReferences) . ' (should be 0)');

        if (count($mockStrategies) == 0 && count($foundMockReferences) == 0) {
            $this->info('🎉 SUCCESS: All mock data has been cleaned up!');
            $this->info('Only real data extraction strategies remain.');
        } else {
            $this->error('⚠️  WARNING: Some mock data still exists and needs to be removed.');
        }

        $this->info('📋 NEXT STEPS:');
        $this->info('1. Implement real data extraction for each carrier');
        $this->info('2. Test real data extraction with actual carrier websites');
        $this->info('3. Only show schedules that exist in reality');
        $this->info('4. Never use mock data again');

        return 0;
    }
}



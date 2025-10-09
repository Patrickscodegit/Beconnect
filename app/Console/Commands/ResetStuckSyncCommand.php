<?php

namespace App\Console\Commands;

use App\Models\ScheduleSyncLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class ResetStuckSyncCommand extends Command
{
    protected $signature = 'schedules:reset-stuck-sync 
                            {--force : Force reset without confirmation}
                            {--older-than=30 : Reset syncs older than X minutes}';

    protected $description = 'Reset stuck sync operations that have been running too long';

    public function handle()
    {
        $olderThan = $this->option('older-than');
        $force = $this->option('force');

        $this->info("🔍 Checking for stuck sync operations older than {$olderThan} minutes...");

        // Find stuck syncs (running but started more than X minutes ago)
        $stuckSyncs = ScheduleSyncLog::whereNull('completed_at')
            ->where('started_at', '<', now()->subMinutes($olderThan))
            ->get();

        if ($stuckSyncs->isEmpty()) {
            $this->info('✅ No stuck sync operations found.');
            return 0;
        }

        $this->warn("⚠️  Found {$stuckSyncs->count()} stuck sync operation(s):");
        
        foreach ($stuckSyncs as $sync) {
            $this->line("   - ID: {$sync->id}, Started: {$sync->started_at}, Type: {$sync->sync_type}");
        }

        if (!$force) {
            if (!$this->confirm('Do you want to reset these stuck syncs?')) {
                $this->info('❌ Operation cancelled.');
                return 0;
            }
        }

        // Reset stuck syncs
        $resetCount = 0;
        foreach ($stuckSyncs as $sync) {
            $sync->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => 'Reset by admin - sync was stuck for more than ' . $olderThan . ' minutes',
                'details' => array_merge($sync->details ?? [], [
                    'reset_at' => now()->toISOString(),
                    'reset_reason' => 'stuck_sync_reset'
                ])
            ]);
            $resetCount++;
        }

        $this->info("✅ Reset {$resetCount} stuck sync operation(s).");

        // Check queue status
        $this->info('🔍 Checking queue status...');
        
        try {
            $queueSize = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();
            
            $this->line("   - Pending jobs: {$queueSize}");
            $this->line("   - Failed jobs: {$failedJobs}");
            
            if ($queueSize > 0) {
                $this->warn('⚠️  There are pending jobs in the queue. Make sure queue workers are running.');
            }
            
            if ($failedJobs > 0) {
                $this->warn('⚠️  There are failed jobs. Run "php artisan queue:failed" to see details.');
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Could not check queue status: {$e->getMessage()}");
        }

        $this->info('🎉 Sync reset completed! The sync button should now work properly.');
        
        return 0;
    }
}
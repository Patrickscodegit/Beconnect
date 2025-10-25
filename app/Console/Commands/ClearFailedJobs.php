<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearFailedJobs extends Command
{
    protected $signature = 'queue:clear-failed';
    protected $description = 'Clear all failed jobs from the queue';

    public function handle()
    {
        $failedCount = DB::table('failed_jobs')->count();
        
        if ($failedCount === 0) {
            $this->info('No failed jobs to clear.');
            return;
        }
        
        DB::table('failed_jobs')->truncate();
        
        $this->info("Cleared {$failedCount} failed jobs.");
        
        // Also show current queue status
        $pendingJobs = DB::table('jobs')->count();
        $this->info("Current queue status:");
        $this->info("- Pending jobs: {$pendingJobs}");
        $this->info("- Failed jobs: 0 (cleared)");
    }
}

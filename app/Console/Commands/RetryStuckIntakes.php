<?php

namespace App\Console\Commands;

use App\Models\Intake;
use App\Jobs\ProcessIntake;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryStuckIntakes extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'intake:retry-stuck 
                            {--hours=2 : Hours to consider an intake as stuck}
                            {--dry-run : Show what would be retried without actually retrying}
                            {--force : Force retry even if intake has robaws_client_id}';

    /**
     * The console command description.
     */
    protected $description = 'Detect and retry intakes that are stuck in processing status';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        
        $this->info("Looking for intakes stuck in 'processing' status for more than {$hours} hours...");
        
        // Find stuck intakes
        $stuckIntakes = Intake::where('status', 'processing')
            ->where('created_at', '<', now()->subHours($hours))
            ->when(!$force, function($query) {
                return $query->whereNull('robaws_client_id');
            })
            ->with(['files', 'documents'])
            ->get();
            
        if ($stuckIntakes->isEmpty()) {
            $this->info('No stuck intakes found.');
            return 0;
        }
        
        $this->info("Found {$stuckIntakes->count()} stuck intakes:");
        
        $tableData = [];
        foreach ($stuckIntakes as $intake) {
            $filesCount = $intake->files->count();
            $docsWithExtraction = $intake->documents->whereNotNull('extraction_data')->count();
            $totalDocs = $intake->documents->count();
            
            $tableData[] = [
                $intake->id,
                $intake->status,
                $intake->created_at->format('Y-m-d H:i:s'),
                $filesCount,
                "{$docsWithExtraction}/{$totalDocs}",
                $intake->robaws_client_id ?: 'NULL',
                $intake->last_export_error ?: 'NONE'
            ];
        }
        
        $this->table([
            'ID', 'Status', 'Created', 'Files', 'Extraction', 'Client ID', 'Last Error'
        ], $tableData);
        
        if ($dryRun) {
            $this->warn('DRY RUN: No intakes were actually retried.');
            return 0;
        }
        
        if (!$this->confirm('Do you want to retry these stuck intakes?')) {
            $this->info('Operation cancelled.');
            return 0;
        }
        
        $retriedCount = 0;
        $failedCount = 0;
        
        foreach ($stuckIntakes as $intake) {
            try {
                $this->info("Retrying intake #{$intake->id}...");
                
                // Reset status and clear any previous errors
                $intake->update([
                    'status' => 'processing',
                    'last_export_error' => null,
                    'last_export_error_at' => null,
                ]);
                
                // Dispatch the intake for reprocessing
                ProcessIntake::dispatch($intake);
                
                $retriedCount++;
                
                Log::info('Stuck intake retried via artisan command', [
                    'intake_id' => $intake->id,
                    'original_status' => 'processing',
                    'hours_stuck' => $intake->created_at->diffInHours(now()),
                    'files_count' => $intake->files->count(),
                    'documents_count' => $intake->documents->count(),
                    'extraction_complete' => $intake->documents->whereNotNull('extraction_data')->count()
                ]);
                
            } catch (\Exception $e) {
                $failedCount++;
                $this->error("Failed to retry intake #{$intake->id}: {$e->getMessage()}");
                
                Log::error('Failed to retry stuck intake', [
                    'intake_id' => $intake->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        $this->info("Retry operation completed:");
        $this->info("- Successfully retried: {$retriedCount}");
        if ($failedCount > 0) {
            $this->warn("- Failed to retry: {$failedCount}");
        }
        
        return 0;
    }
}

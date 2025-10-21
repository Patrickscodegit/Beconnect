<?php

namespace App\Console\Commands;

use App\Services\Robaws\RobawsCustomerSyncService;
use Illuminate\Console\Command;

class SyncRobawsCustomers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'robaws:sync-customers 
                            {--full : Perform full sync instead of incremental}
                            {--push : Push local changes back to Robaws}
                            {--dry-run : Inspect customer data without saving}
                            {--limit= : Limit number of customers to sync (for testing)}
                            {--client-id= : Sync specific customer by ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync customers from Robaws API (bi-directional)';

    /**
     * Execute the console command.
     */
    public function handle(RobawsCustomerSyncService $syncService): int
    {
        // Handle push mode
        if ($this->option('push')) {
            $this->info('Pushing local customer changes to Robaws...');
            $stats = $syncService->pushAllPendingUpdates();
            
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Pushed', $stats['pushed']],
                    ['Failed', $stats['failed']],
                ]
            );
            
            $this->info('✅ Push completed');
            return Command::SUCCESS;
        }
        
        // Handle single customer sync
        if ($clientId = $this->option('client-id')) {
            $this->info("Syncing customer: {$clientId}");
            $customer = $syncService->syncSingleCustomer($clientId);
            $this->info("✅ Synced: {$customer->name} (Role: {$customer->role})");
            return Command::SUCCESS;
        }
        
        // Handle full/incremental sync
        $fullSync = $this->option('full');
        $dryRun = $this->option('dry-run');
        $limit = $this->option('limit') ? (int)$this->option('limit') : null;
        
        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No data will be saved');
        }
        
        $this->info($fullSync ? 'Performing full sync...' : 'Performing incremental sync...');
        
        if ($limit) {
            $this->info("Limiting to {$limit} customers");
        }
        
        $stats = $syncService->syncAllCustomers($fullSync, $dryRun, $limit);
        
        // Show sample data if dry-run
        if ($dryRun && !empty($stats['sample_data'])) {
            $this->info("\n📊 Sample Customer Data (First 10):\n");
            
            foreach ($stats['sample_data'] as $index => $sample) {
                $this->line("--- Customer " . ($index + 1) . " ---");
                $this->line("ID: {$sample['id']}");
                $this->line("Name: {$sample['name']}");
                $this->line("Extracted Role: " . ($sample['extracted_role'] ?? 'NULL'));
                
                // Show custom fields structure
                if (isset($sample['structure']['custom_fields'])) {
                    $this->line("Custom Fields: " . json_encode($sample['structure']['custom_fields'], JSON_PRETTY_PRINT));
                }
                if (isset($sample['structure']['extraFields'])) {
                    $this->line("Extra Fields: " . json_encode($sample['structure']['extraFields'], JSON_PRETTY_PRINT));
                }
                $this->line("");
            }
        }
        
        // Show stats table
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Fetched', $stats['total_fetched']],
                ['Created', $stats['created']],
                ['Updated', $stats['updated']],
                ['Skipped (dry-run)', $stats['skipped']],
                ['Errors', $stats['errors']],
            ]
        );
        
        if ($stats['errors'] > 0) {
            $this->warn("⚠️ {$stats['errors']} customers had errors - check logs");
        }
        
        if ($dryRun) {
            $this->info("\n✅ Dry run completed - Review sample data above to verify role extraction");
        } else {
            $this->info('✅ Customer sync completed successfully');
        }
        
        return Command::SUCCESS;
    }
}

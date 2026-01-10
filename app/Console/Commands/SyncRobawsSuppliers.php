<?php

namespace App\Console\Commands;

use App\Services\Robaws\RobawsSupplierSyncService;
use Illuminate\Console\Command;

class SyncRobawsSuppliers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'robaws:sync-suppliers 
                            {--full : Perform full sync instead of incremental}
                            {--push : Push local changes back to Robaws}
                            {--dry-run : Inspect supplier data without saving}
                            {--limit= : Limit number of suppliers to sync (for testing)}
                            {--supplier-id= : Sync specific supplier by ID}
                            {--include-contacts : Include and sync supplier contacts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync suppliers from Robaws API (bi-directional)';

    /**
     * Execute the console command.
     */
    public function handle(RobawsSupplierSyncService $syncService): int
    {
        // Handle push mode
        if ($this->option('push')) {
            $this->info('Pushing local supplier changes to Robaws...');
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
        
        // Handle single supplier sync
        if ($supplierId = $this->option('supplier-id')) {
            $includeContacts = $this->option('include-contacts');
            $this->info("Syncing supplier: {$supplierId}" . ($includeContacts ? ' (with contacts)' : ''));
            $supplier = $syncService->syncSingleSupplier($supplierId, $includeContacts);
            $contactCount = $supplier->contacts()->count();
            $this->info("✅ Synced: {$supplier->name} (Type: {$supplier->supplier_type}, Code: {$supplier->code}, Contacts: {$contactCount})");
            return Command::SUCCESS;
        }
        
        // Handle full/incremental sync
        $fullSync = $this->option('full');
        $dryRun = $this->option('dry-run');
        $limit = $this->option('limit') ? (int)$this->option('limit') : null;
        $includeContacts = $this->option('include-contacts');
        
        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No data will be saved');
        }
        
        $this->info($fullSync ? 'Performing full sync...' : 'Performing incremental sync...');
        
        if ($includeContacts) {
            $this->warn('⚠️  Including contacts - this will make individual API calls for each supplier and may be slow');
        }
        
        if ($limit) {
            $this->info("Limiting to {$limit} suppliers");
        }
        
        $stats = $syncService->syncAllSuppliers($fullSync, $dryRun, $limit, $includeContacts);
        
        // Show sample data if dry-run
        if ($dryRun && !empty($stats['sample_data'])) {
            $this->info("\n📊 Sample Supplier Data (First 10):\n");
            
            foreach ($stats['sample_data'] as $index => $sample) {
                $this->line("--- Supplier " . ($index + 1) . " ---");
                $this->line("ID: {$sample['id']}");
                $this->line("Name: {$sample['name']}");
                $this->line("Extracted Type: " . ($sample['extracted_type'] ?? 'NULL'));
                $this->line("Extracted Code: " . ($sample['extracted_code'] ?? 'NULL'));
                
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
        $tableData = [
            ['Total Fetched', $stats['total_fetched']],
            ['Created', $stats['created']],
            ['Updated', $stats['updated']],
            ['Skipped', $stats['skipped']],
            ['Errors', $stats['errors']],
        ];
        
        if (isset($stats['contacts_synced']) && $stats['contacts_synced'] > 0) {
            $tableData[] = ['Contacts Synced', $stats['contacts_synced']];
        }
        
        $this->table(
            ['Metric', 'Count'],
            $tableData
        );
        
        if ($stats['errors'] > 0) {
            $this->warn("⚠️  {$stats['errors']} errors occurred during sync. Check logs for details.");
        } else {
            $this->info('✅ Sync completed successfully');
        }
        
        return Command::SUCCESS;
    }
}

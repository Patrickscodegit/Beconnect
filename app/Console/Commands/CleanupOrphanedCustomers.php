<?php

namespace App\Console\Commands;

use App\Models\RobawsCustomerCache;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Console\Command;

class CleanupOrphanedCustomers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'robaws:cleanup-orphaned-customers
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--group= : Only cleanup a specific duplicate group name}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup orphaned customer records that exist locally but not in Robaws';

    protected RobawsApiClient $apiClient;

    public function __construct(RobawsApiClient $apiClient)
    {
        parent::__construct();
        $this->apiClient = $apiClient;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Scanning for orphaned customer records...');
        $this->newLine();

        // Get duplicate groups or specific group
        $query = RobawsCustomerCache::select('name')
            ->selectRaw('count(*) as count')
            ->groupBy('name')
            ->havingRaw('count(*) > 1');

        if ($groupName = $this->option('group')) {
            $query->where('name', $groupName);
        }

        $duplicateGroups = $query->get();

        if ($duplicateGroups->isEmpty()) {
            $this->info('✅ No duplicate groups found!');
            return 0;
        }

        $this->table(
            ['Group Name', 'Total Records'],
            $duplicateGroups->map(fn($g) => [$g->name, $g->count])
        );
        $this->newLine();

        // Scan each group for orphaned records
        $orphanedRecords = [];
        $totalScanned = 0;

        foreach ($duplicateGroups as $group) {
            $this->info("Checking '{$group->name}' group ({$group->count} records)...");
            
            $records = RobawsCustomerCache::where('name', $group->name)->get();
            
            foreach ($records as $record) {
                $totalScanned++;
                
                // Check if record exists in Robaws
                try {
                    $this->apiClient->getClientById($record->robaws_client_id);
                    // Exists in Robaws - skip
                } catch (\Exception $e) {
                    if (strpos($e->getMessage(), '404') !== false) {
                        // Not in Robaws - check for intakes
                        $intakeCount = $record->intakes()->count();
                        
                        if ($intakeCount > 0) {
                            $this->warn("  ⚠️  ID {$record->robaws_client_id}: Orphaned but has {$intakeCount} intake(s) - SKIPPING");
                        } else {
                            $orphanedRecords[] = [
                                'id' => $record->id,
                                'robaws_client_id' => $record->robaws_client_id,
                                'name' => $record->name,
                                'email' => $record->email,
                            ];
                        }
                    }
                }
            }
        }

        $this->newLine();
        $this->info("📊 Scanned {$totalScanned} records across {$duplicateGroups->count()} duplicate groups");
        $this->info("🗑️  Found " . count($orphanedRecords) . " orphaned records (no intakes, not in Robaws)");
        $this->newLine();

        if (empty($orphanedRecords)) {
            $this->info('✅ No orphaned records to clean up!');
            return 0;
        }

        // Show orphaned records
        $this->table(
            ['ID', 'Robaws ID', 'Name', 'Email'],
            array_map(fn($r) => [
                $r['id'],
                $r['robaws_client_id'],
                $r['name'],
                $r['email'] ?? '(none)'
            ], $orphanedRecords)
        );
        $this->newLine();

        // Dry run mode
        if ($this->option('dry-run')) {
            $this->info('🔍 DRY RUN MODE: No records were deleted');
            $this->info('Run without --dry-run to actually delete these records');
            return 0;
        }

        // Confirm deletion
        if (!$this->option('force')) {
            if (!$this->confirm('Do you want to delete these ' . count($orphanedRecords) . ' orphaned records?', false)) {
                $this->info('❌ Cleanup cancelled');
                return 0;
            }
        }

        // Delete orphaned records
        $this->info('🗑️  Deleting orphaned records...');
        $deletedCount = 0;

        foreach ($orphanedRecords as $record) {
            try {
                RobawsCustomerCache::where('id', $record['id'])->delete();
                $deletedCount++;
                $this->line("  ✓ Deleted ID {$record['robaws_client_id']}: {$record['name']}");
            } catch (\Exception $e) {
                $this->error("  ✗ Failed to delete ID {$record['robaws_client_id']}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("✅ Successfully deleted {$deletedCount} orphaned records");

        // Show summary
        $remaining = RobawsCustomerCache::count();
        $this->info("📊 Total customers remaining: {$remaining}");

        return 0;
    }
}


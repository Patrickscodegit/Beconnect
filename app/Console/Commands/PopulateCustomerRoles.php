<?php

namespace App\Console\Commands;

use App\Models\Intake;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PopulateCustomerRoles extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'intake:populate-customer-roles 
                            {--default=BUYER : Default role to assign if none specified}
                            {--dry-run : Show what would be updated without actually updating}
                            {--update-robaws : Also update the Role extra field in Robaws for clients}';

    /**
     * The console command description.
     */
    protected $description = 'Populate customer_role field for existing intakes and optionally update Robaws clients';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $defaultRole = $this->option('default');
        $dryRun = $this->option('dry-run');
        $updateRobaws = $this->option('update-robaws');
        
        $this->info("Populating customer roles for intakes without a role...");
        $this->info("Default role: {$defaultRole}");
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }
        
        // Find intakes without customer_role
        $intakesWithoutRole = Intake::whereNull('customer_role')->get();
        
        if ($intakesWithoutRole->isEmpty()) {
            $this->info('All intakes already have customer roles assigned.');
            return 0;
        }
        
        $this->info("Found {$intakesWithoutRole->count()} intakes without customer role:");
        
        $tableData = [];
        foreach ($intakesWithoutRole as $intake) {
            $tableData[] = [
                $intake->id,
                $intake->customer_name ?: 'NULL',
                $intake->contact_email ?: 'NULL',
                $intake->robaws_client_id ?: 'NULL',
                $intake->status,
            ];
        }
        
        $this->table([
            'ID', 'Customer Name', 'Email', 'Robaws Client ID', 'Status'
        ], $tableData);
        
        if ($dryRun) {
            $this->warn("DRY RUN: Would assign role '{$defaultRole}' to {$intakesWithoutRole->count()} intakes.");
            if ($updateRobaws) {
                $clientCount = $intakesWithoutRole->whereNotNull('robaws_client_id')->count();
                $this->warn("DRY RUN: Would also update {$clientCount} Robaws clients with the Role extra field.");
            }
            return 0;
        }
        
        if (!$this->confirm("Assign role '{$defaultRole}' to these {$intakesWithoutRole->count()} intakes?")) {
            $this->info('Operation cancelled.');
            return 0;
        }
        
        $updatedCount = 0;
        $robawsUpdatedCount = 0;
        $robawsFailedCount = 0;
        
        $apiClient = $updateRobaws ? app(RobawsApiClient::class) : null;
        
        foreach ($intakesWithoutRole as $intake) {
            try {
                // Update intake
                $intake->update(['customer_role' => $defaultRole]);
                $updatedCount++;
                
                $this->info("Updated intake #{$intake->id} with role: {$defaultRole}");
                
                // Update Robaws client if requested and client ID exists
                if ($updateRobaws && $intake->robaws_client_id && $apiClient) {
                    try {
                        // Use reflection to access private method updateClientExtraField
                        $reflection = new \ReflectionClass($apiClient);
                        $method = $reflection->getMethod('updateClientExtraField');
                        $method->setAccessible(true);
                        $method->invoke($apiClient, (int)$intake->robaws_client_id, 'Role', $defaultRole);
                        
                        $robawsUpdatedCount++;
                        $this->info("  → Also updated Robaws client #{$intake->robaws_client_id}");
                        
                    } catch (\Exception $e) {
                        $robawsFailedCount++;
                        $this->error("  → Failed to update Robaws client #{$intake->robaws_client_id}: {$e->getMessage()}");
                        
                        Log::error('Failed to update Robaws client role', [
                            'intake_id' => $intake->id,
                            'client_id' => $intake->robaws_client_id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
            } catch (\Exception $e) {
                $this->error("Failed to update intake #{$intake->id}: {$e->getMessage()}");
                
                Log::error('Failed to populate customer role', [
                    'intake_id' => $intake->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        $this->info("\nPopulation complete:");
        $this->info("- Intakes updated: {$updatedCount}");
        if ($updateRobaws) {
            $this->info("- Robaws clients updated: {$robawsUpdatedCount}");
            if ($robawsFailedCount > 0) {
                $this->warn("- Robaws clients failed: {$robawsFailedCount}");
            }
        }
        
        return 0;
    }
}


<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeactivateRobawsWebhook extends Command
{
    protected $signature = 'robaws:deactivate-webhook 
                            {id? : Database ID of the webhook to deactivate}
                            {--all-except=1 : Deactivate all webhooks except the one with this DB ID}';
    
    protected $description = 'Deactivate a Robaws webhook registration';

    public function handle(): int
    {
        $id = $this->argument('id');
        $allExcept = $this->option('all-except');
        
        // Show current webhooks
        $this->info('📋 Current webhooks:');
        $this->newLine();
        
        $webhooks = DB::table('webhook_configurations')
            ->where('provider', 'robaws')
            ->orderBy('registered_at', 'desc')
            ->get();
            
        if ($webhooks->isEmpty()) {
            $this->info('No webhooks found.');
            return 0;
        }
        
        $tableData = [];
        foreach ($webhooks as $webhook) {
            $status = $webhook->is_active ? '<fg=green>✓ ACTIVE</>' : '<fg=red>✗ INACTIVE</>';
            $tableData[] = [
                $webhook->id,
                $webhook->webhook_id,
                $status,
                $webhook->registered_at,
            ];
        }
        
        $this->table(['DB ID', 'Webhook ID', 'Status', 'Registered At'], $tableData);
        
        // If --all-except option is used
        if ($allExcept) {
            $keepId = (int) $allExcept;
            $toDeactivate = $webhooks->where('id', '!=', $keepId)->where('is_active', true);
            
            if ($toDeactivate->isEmpty()) {
                $this->info('✅ No other active webhooks to deactivate.');
                return 0;
            }
            
            $this->newLine();
            $this->warn("⚠️  This will deactivate {$toDeactivate->count()} webhook(s), keeping only ID {$keepId} active.");
            
            if (!$this->confirm('Continue?')) {
                $this->info('Cancelled.');
                return 0;
            }
            
            DB::table('webhook_configurations')
                ->where('provider', 'robaws')
                ->where('id', '!=', $keepId)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
                
            $this->info("✅ Deactivated {$toDeactivate->count()} webhook(s).");
            $this->info("💡 Only webhook ID {$keepId} is now active.");
            
            return 0;
        }
        
        // If specific ID is provided
        if ($id) {
            $webhook = $webhooks->firstWhere('id', $id);
            
            if (!$webhook) {
                $this->error("❌ Webhook with ID {$id} not found.");
                return 1;
            }
            
            if (!$webhook->is_active) {
                $this->info("ℹ️  Webhook ID {$id} is already inactive.");
                return 0;
            }
            
            $this->newLine();
            $this->warn("⚠️  Deactivating webhook:");
            $this->line("  DB ID: {$webhook->id}");
            $this->line("  Webhook ID: {$webhook->webhook_id}");
            $this->line("  URL: {$webhook->url}");
            
            if (!$this->confirm('Are you sure?')) {
                $this->info('Cancelled.');
                return 0;
            }
            
            DB::table('webhook_configurations')
                ->where('id', $id)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
                
            $this->info("✅ Webhook ID {$id} has been deactivated.");
            
            return 0;
        }
        
        // Interactive mode - ask which to deactivate
        $this->newLine();
        $idToDeactivate = $this->ask('Enter the DB ID of the webhook to deactivate (or press Enter to cancel)');
        
        if (!$idToDeactivate) {
            $this->info('Cancelled.');
            return 0;
        }
        
        $webhook = $webhooks->firstWhere('id', (int) $idToDeactivate);
        
        if (!$webhook) {
            $this->error("❌ Webhook with ID {$idToDeactivate} not found.");
            return 1;
        }
        
        if (!$webhook->is_active) {
            $this->info("ℹ️  Webhook ID {$idToDeactivate} is already inactive.");
            return 0;
        }
        
        DB::table('webhook_configurations')
            ->where('id', $idToDeactivate)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
            
        $this->info("✅ Webhook ID {$idToDeactivate} has been deactivated.");
        
        return 0;
    }
}


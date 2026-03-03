<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeactivateRobawsWebhook extends Command
{
    protected $signature = 'robaws:deactivate-webhook 
                            {id? : Database ID of the webhook to deactivate}
                            {--all-except=1 : Deactivate all webhooks except the one with this DB ID (scoped per provider)}
                            {--provider= : When using --all-except, scope to this provider only}';

    protected $description = 'Deactivate a Robaws webhook registration';

    public function handle(): int
    {
        $id = $this->argument('id');
        $allExcept = $this->option('all-except');
        $providerFilter = $this->option('provider');

        $this->info('Current webhooks:');
        $this->newLine();

        $query = DB::table('webhook_configurations');

        if ($providerFilter) {
            $query->where('provider', $providerFilter);
        }

        $webhooks = $query->orderBy('provider')->orderBy('registered_at', 'desc')->get();

        if ($webhooks->isEmpty()) {
            $this->info('No webhooks found.');
            return 0;
        }

        $tableData = [];
        foreach ($webhooks as $webhook) {
            $status = $webhook->is_active ? '<fg=green>ACTIVE</>' : '<fg=red>INACTIVE</>';
            $tableData[] = [
                $webhook->id,
                $webhook->provider,
                $webhook->webhook_id,
                $status,
                $webhook->url,
                $webhook->registered_at,
            ];
        }

        $this->table(['DB ID', 'Provider', 'Webhook ID', 'Status', 'URL', 'Registered At'], $tableData);

        if ($allExcept !== null && $allExcept !== '') {
            $keepId = (int) $allExcept;
            $keepWebhook = $webhooks->firstWhere('id', $keepId);

            $toDeactivateQuery = DB::table('webhook_configurations')
                ->where('id', '!=', $keepId)
                ->where('is_active', true);

            if ($providerFilter) {
                $toDeactivateQuery->where('provider', $providerFilter);
            } elseif ($keepWebhook) {
                $toDeactivateQuery->where('provider', $keepWebhook->provider);
            } else {
                $this->warn("Webhook ID {$keepId} not found. Use --provider= to scope, or specify a valid ID.");
                return 1;
            }

            $toDeactivate = $toDeactivateQuery->get();

            if ($toDeactivate->isEmpty()) {
                $this->info('No other active webhooks to deactivate.');
                return 0;
            }

            $this->newLine();
            $this->warn("This will deactivate {$toDeactivate->count()} webhook(s), keeping only ID {$keepId} active.");

            if (!$this->confirm('Continue?')) {
                $this->info('Cancelled.');
                return 0;
            }

            $ids = $toDeactivate->pluck('id')->toArray();
            DB::table('webhook_configurations')
                ->whereIn('id', $ids)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);

            $this->info("Deactivated {$toDeactivate->count()} webhook(s).");
            $this->info("Only webhook ID {$keepId} is now active for its provider.");

            return 0;
        }

        if ($id) {
            $webhook = $webhooks->firstWhere('id', (int) $id);

            if (!$webhook) {
                $this->error("Webhook with ID {$id} not found.");
                return 1;
            }

            if (!$webhook->is_active) {
                $this->info("Webhook ID {$id} is already inactive.");
                return 0;
            }

            $this->newLine();
            $this->warn("Deactivating webhook:");
            $this->line("  DB ID: {$webhook->id}");
            $this->line("  Provider: {$webhook->provider}");
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

            $this->info("Webhook ID {$id} has been deactivated.");

            return 0;
        }

        $this->newLine();
        $idToDeactivate = $this->ask('Enter the DB ID of the webhook to deactivate (or press Enter to cancel)');

        if (!$idToDeactivate) {
            $this->info('Cancelled.');
            return 0;
        }

        $webhook = $webhooks->firstWhere('id', (int) $idToDeactivate);

        if (!$webhook) {
            $this->error("Webhook with ID {$idToDeactivate} not found.");
            return 1;
        }

        if (!$webhook->is_active) {
            $this->info("Webhook ID {$idToDeactivate} is already inactive.");
            return 0;
        }

        DB::table('webhook_configurations')
            ->where('id', $idToDeactivate)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        $this->info("Webhook ID {$idToDeactivate} has been deactivated.");

        return 0;
    }
}

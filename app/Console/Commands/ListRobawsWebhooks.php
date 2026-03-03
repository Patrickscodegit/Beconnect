<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ListRobawsWebhooks extends Command
{
    protected $signature = 'robaws:list-webhooks 
                            {--active-only : Show only active webhooks}
                            {--provider= : Filter by provider (e.g. robaws, robaws_customers)}';

    protected $description = 'List all registered Robaws webhooks';

    public function handle(): int
    {
        $activeOnly = $this->option('active-only');
        $providerFilter = $this->option('provider');

        $query = DB::table('webhook_configurations');

        if ($providerFilter) {
            $query->where('provider', $providerFilter);
        }

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $webhooks = $query->orderBy('provider')->orderBy('registered_at', 'desc')->get();

        if ($webhooks->isEmpty()) {
            $this->info('No webhooks found.');
            return 0;
        }

        $this->info('Robaws Webhooks:');
        $this->newLine();

        $tableData = [];
        foreach ($webhooks as $webhook) {
            $status = $webhook->is_active ? '<fg=green>ACTIVE</>' : '<fg=red>INACTIVE</>';
            $events = json_decode($webhook->events, true);
            $eventsList = is_array($events) ? implode(', ', $events) : $webhook->events;

            $tableData[] = [
                $webhook->id,
                $webhook->provider,
                $webhook->webhook_id,
                $status,
                $webhook->url,
                $eventsList,
                $webhook->registered_at,
            ];
        }

        $this->table(
            ['DB ID', 'Provider', 'Webhook ID', 'Status', 'URL', 'Events', 'Registered At'],
            $tableData
        );

        $activeCount = $webhooks->where('is_active', true)->count();
        $inactiveCount = $webhooks->count() - $activeCount;

        $this->newLine();
        $this->info("Summary:");
        $this->line("  Active:   {$activeCount}");
        $this->line("  Inactive: {$inactiveCount}");
        $this->line("  Total:    {$webhooks->count()}");

        $activeByProvider = $webhooks->where('is_active', true)->groupBy('provider');
        foreach ($activeByProvider as $provider => $items) {
            if ($items->count() > 1) {
                $this->newLine();
                $this->error("WARNING: {$provider} has {$items->count()} active webhooks!");
                $this->warn("This will cause duplicate webhook calls from Robaws.");
                $this->info("Use 'php artisan robaws:deactivate-webhook <id>' to deactivate duplicates");
            }
        }

        return 0;
    }
}

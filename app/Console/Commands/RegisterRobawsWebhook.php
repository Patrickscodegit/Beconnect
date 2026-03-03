<?php

namespace App\Console\Commands;

use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RegisterRobawsWebhook extends Command
{
    protected $signature = 'robaws:register-webhook 
                            {--type=articles : Webhook type: articles, customers}
                            {--url= : Override webhook URL}';

    protected $description = 'Register webhook endpoint with Robaws API';

    private const TYPE_CONFIG = [
        'articles' => [
            'provider' => 'robaws',
            'path' => '/api/webhooks/robaws/articles',
            'events' => ['article.created', 'article.updated', 'article.stock-changed'],
        ],
        'customers' => [
            'provider' => 'robaws_customers',
            'path' => '/api/webhooks/robaws/customers',
            'events' => ['client.created', 'client.updated'],
        ],
    ];

    public function __construct(
        private RobawsApiClient $apiClient
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $type = $this->option('type');
        if (!isset(self::TYPE_CONFIG[$type])) {
            $this->error("Invalid type: {$type}. Valid types: " . implode(', ', array_keys(self::TYPE_CONFIG)));
            return 1;
        }

        $config = self::TYPE_CONFIG[$type];
        $webhookUrl = $this->option('url') ?? (rtrim(config('app.url'), '/') . $config['path']);
        $environment = config('app.env');

        $this->info("Registering {$type} webhook for {$environment} environment");
        $this->info("URL: {$webhookUrl}");
        $this->info("Provider: {$config['provider']}");
        $this->info("Events: " . implode(', ', $config['events']));

        $allExisting = DB::table('webhook_configurations')
            ->where('provider', $config['provider'])
            ->get();

        if ($allExisting->count() > 0) {
            $this->newLine();
            $this->error('EXISTING WEBHOOKS FOUND for this provider:');
            $this->newLine();

            foreach ($allExisting as $webhook) {
                $status = $webhook->is_active ? 'ACTIVE' : 'INACTIVE';
                $this->line("  [{$status}] ID: {$webhook->webhook_id}");
                $this->line("  URL: {$webhook->url}");
                $this->line("  Registered: {$webhook->registered_at}");
                $this->line("  ---");
            }

            $activeCount = $allExisting->where('is_active', true)->count();

            if ($activeCount > 0) {
                $this->newLine();
                $this->error("You already have {$activeCount} ACTIVE webhook(s) for {$config['provider']}!");
                $this->warn("Registering another webhook will create DUPLICATES.");
                $this->warn("This will cause Robaws to send multiple webhook calls for the same event.");
                $this->newLine();
                $this->info("Use 'php artisan robaws:list-webhooks' to see all webhooks");
                $this->info("Use 'php artisan robaws:deactivate-webhook' to deactivate duplicates");
                $this->newLine();

                if (!$this->confirm('Are you ABSOLUTELY SURE you want to create another webhook?', false)) {
                    $this->info('Cancelled. No duplicate webhook created.');
                    return 0;
                }

                $this->error('FINAL WARNING: This will create a duplicate!');
                if (!$this->confirm('Type YES to proceed', false)) {
                    $this->info('Cancelled. No duplicate webhook created.');
                    return 0;
                }
            }
        }

        try {
            $result = $this->apiClient->registerWebhook([
                'url' => $webhookUrl,
                'events' => $config['events'],
            ]);

            if ($result['success']) {
                $webhookId = $result['data']['id'] ?? null;
                $secret = $result['data']['secret'] ?? null;

                DB::table('webhook_configurations')->insert([
                    'provider' => $config['provider'],
                    'webhook_id' => $webhookId,
                    'secret' => $secret,
                    'url' => $webhookUrl,
                    'events' => json_encode($config['events']),
                    'is_active' => true,
                    'registered_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->info("Webhook registered successfully!");
                $this->line("Webhook ID: {$webhookId}");
                $this->newLine();
                $this->warn("IMPORTANT: The secret is stored in webhook_configurations. For {$config['provider']} you may also add to .env:");
                $this->line("ROBAWS_{$type}_WEBHOOK_SECRET={$secret}");

                return 0;
            }

            $this->error('Failed to register webhook');
            $this->line('Error: ' . ($result['error'] ?? 'Unknown error'));
            return 1;
        } catch (\Exception $e) {
            $this->error('Exception: ' . $e->getMessage());
            return 1;
        }
    }
}

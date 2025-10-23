<?php

namespace App\Console\Commands;

use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RegisterRobawsWebhook extends Command
{
    protected $signature = 'robaws:register-webhook 
                            {--url= : Webhook URL (defaults to app URL)}';
    
    protected $description = 'Register webhook endpoint with Robaws API';

    public function __construct(
        private RobawsApiClient $apiClient
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $environment = config('app.env');
        $webhookUrl = $this->option('url') ?? config('app.url') . '/api/webhooks/robaws/articles';
        
        $this->info("🔄 Registering webhook for {$environment} environment");
        $this->info("URL: {$webhookUrl}");
        
        // Check ALL existing webhooks (active and inactive)
        $allExisting = DB::table('webhook_configurations')
            ->where('provider', 'robaws')
            ->get();
            
        if ($allExisting->count() > 0) {
            $this->newLine();
            $this->error('⚠️  EXISTING WEBHOOKS FOUND:');
            $this->newLine();
            
            foreach ($allExisting as $webhook) {
                $status = $webhook->is_active ? '✓ ACTIVE' : '✗ INACTIVE';
                $this->line("  [{$status}] ID: {$webhook->webhook_id}");
                $this->line("  URL: {$webhook->url}");
                $this->line("  Registered: {$webhook->registered_at}");
                $this->line("  ---");
            }
            
            $activeCount = $allExisting->where('is_active', true)->count();
            
            if ($activeCount > 0) {
                $this->newLine();
                $this->error("❌ You already have {$activeCount} ACTIVE webhook(s) registered!");
                $this->warn("Registering another webhook will create DUPLICATES.");
                $this->warn("This will cause Robaws to send multiple webhook calls for the same event.");
                $this->newLine();
                $this->info("💡 Use 'php artisan robaws:list-webhooks' to see all webhooks");
                $this->info("💡 Use 'php artisan robaws:deactivate-webhook' to deactivate duplicates");
                $this->newLine();
                
                if (!$this->confirm('Are you ABSOLUTELY SURE you want to create another webhook?', false)) {
                    $this->info('✅ Cancelled. No duplicate webhook created.');
                    return 0;
                }
                
                $this->error('⚠️  FINAL WARNING: This will create a duplicate!');
                if (!$this->confirm('Type YES to proceed', false)) {
                    $this->info('✅ Cancelled. No duplicate webhook created.');
                    return 0;
                }
            }
        }
        
        try {
            // Register webhook with Robaws
            $result = $this->apiClient->registerWebhook([
                'url' => $webhookUrl,
                'events' => ['article.created', 'article.updated', 'article.stock-changed']
            ]);
            
            if ($result['success']) {
                $webhookId = $result['data']['id'] ?? null;
                $secret = $result['data']['secret'] ?? null;
                
                // Store in database
                DB::table('webhook_configurations')->insert([
                    'provider' => 'robaws',
                    'webhook_id' => $webhookId,
                    'secret' => $secret,
                    'url' => $webhookUrl,
                    'events' => json_encode(['article.created', 'article.updated', 'article.stock-changed']),
                    'is_active' => true,
                    'registered_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                $this->info("✅ Webhook registered successfully!");
                $this->line("Webhook ID: {$webhookId}");
                $this->newLine();
                $this->warn("⚠️  IMPORTANT: Add this to your .env file:");
                $this->line("ROBAWS_WEBHOOK_SECRET={$secret}");
                
                return 0;
            }
            
            $this->error('❌ Failed to register webhook');
            $this->line('Error: ' . ($result['error'] ?? 'Unknown error'));
            return 1;
            
        } catch (\Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
            return 1;
        }
    }
}
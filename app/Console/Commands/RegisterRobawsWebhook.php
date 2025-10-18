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
        
        // Check if already registered
        $existing = DB::table('webhook_configurations')
            ->where('provider', 'robaws')
            ->where('is_active', true)
            ->first();
            
        if ($existing) {
            $this->warn('⚠️  Webhook already registered!');
            $this->line("Webhook ID: {$existing->webhook_id}");
            $this->line("URL: {$existing->url}");
            
            if (!$this->confirm('Do you want to register a new webhook anyway?')) {
                return 0;
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
<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class TestRobawsWebhook extends Command
{
    protected $signature = 'robaws:test-webhook 
                            {--event=article.updated : Event type to simulate}
                            {--article-id= : Article ID to use (random if not specified)}
                            {--url= : Webhook URL (defaults to local)}';
    
    protected $description = 'Test webhook endpoint by simulating a Robaws webhook event';

    public function handle(): int
    {
        $event = $this->option('event');
        $articleId = $this->option('article-id');
        $url = $this->option('url') ?? config('app.url') . '/api/webhooks/robaws/articles';
        
        // Get or create test article data
        if ($articleId) {
            $article = RobawsArticleCache::where('robaws_article_id', $articleId)->first();
            
            if (!$article) {
                $this->error("Article with Robaws ID {$articleId} not found");
                return 1;
            }
            
            $articleData = [
                'id' => $article->robaws_article_id,
                'code' => $article->article_code,
                'name' => $article->article_name,
                'description' => $article->description,
                'unitPrice' => $article->unit_price,
                'isActive' => $article->is_active,
                'lastModifiedAt' => now()->toIso8601String(),
            ];
        } else {
            // Use random existing article or create fake data
            $article = RobawsArticleCache::inRandomOrder()->first();
            
            if ($article) {
                $articleData = [
                    'id' => $article->robaws_article_id,
                    'code' => $article->article_code,
                    'name' => $article->article_name,
                    'description' => $article->description,
                    'unitPrice' => $article->unit_price,
                    'isActive' => $article->is_active,
                    'lastModifiedAt' => now()->toIso8601String(),
                ];
            } else {
                // No articles in cache, create fake data
                $articleData = [
                    'id' => 'TEST-' . rand(1000, 9999),
                    'code' => 'TEST-CODE-' . rand(100, 999),
                    'name' => 'Test Article - Seafreight ACL Export',
                    'description' => 'Test webhook simulation article',
                    'unitPrice' => 100.00,
                    'isActive' => true,
                    'lastModifiedAt' => now()->toIso8601String(),
                ];
            }
        }
        
        $this->info("🧪 Testing Webhook Endpoint");
        $this->line("URL: {$url}");
        $this->line("Event: {$event}");
        $this->line("Article ID: {$articleData['id']}");
        $this->newLine();
        
        // Build webhook payload
        $timestamp = time();
        $payload = [
            'event' => $event,
            'id' => 'webhook-test-' . $timestamp,
            'data' => $articleData,
            'timestamp' => $timestamp,
        ];
        
        $payloadJson = json_encode($payload);
        
        // Get webhook secret from database
        $config = DB::table('webhook_configurations')
            ->where('provider', 'robaws')
            ->where('is_active', true)
            ->first();
        
        if (!$config) {
            $this->warn('⚠️  No active webhook configuration found');
            $this->line('Sending webhook WITHOUT signature (will be rejected by production)');
            $secret = null;
        } else {
            $secret = $config->secret;
            $this->info('✅ Found webhook secret, will sign request');
        }
        
        // Generate signature
        $signature = null;
        if ($secret) {
            $signedPayload = $timestamp . '.' . $payloadJson;
            $hash = hash_hmac('sha256', $signedPayload, $secret);
            $signature = "t={$timestamp},v1={$hash}";
        }
        
        // Send webhook
        $this->line('📤 Sending webhook...');
        
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Robaws-Signature' => $signature ?? 'test-signature',
            ])->post($url, $payload);
            
            $this->newLine();
            
            if ($response->successful()) {
                $this->info('✅ Webhook sent successfully!');
                $this->line("Status: {$response->status()}");
                $this->line("Response: " . $response->body());
                
                // Check if webhook log was created
                $this->newLine();
                $this->line('🔍 Checking webhook logs...');
                
                $log = \App\Models\RobawsWebhookLog::where('robaws_id', 'webhook-test-' . $timestamp)
                    ->orderBy('created_at', 'desc')
                    ->first();
                
                if ($log) {
                    $this->info("✅ Webhook log created (ID: {$log->id})");
                    $this->line("Status: {$log->status}");
                    
                    if ($log->status === 'failed') {
                        $this->error("Error: {$log->error_message}");
                    } elseif ($log->status === 'processed') {
                        $this->info('✅ Webhook processed successfully!');
                        
                        // Check if article was updated
                        $updatedArticle = RobawsArticleCache::where('robaws_article_id', $articleData['id'])->first();
                        if ($updatedArticle) {
                            $this->info("✅ Article cache updated (ID: {$updatedArticle->id})");
                        }
                    }
                } else {
                    $this->warn('⚠️  No webhook log found (signature may have been rejected)');
                }
                
                return 0;
            } else {
                $this->error('❌ Webhook request failed');
                $this->line("Status: {$response->status()}");
                $this->line("Response: " . $response->body());
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
            return 1;
        }
    }
}


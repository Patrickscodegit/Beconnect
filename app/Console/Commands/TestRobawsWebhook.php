<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use App\Models\RobawsCustomerCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TestRobawsWebhook extends Command
{
    protected $signature = 'robaws:test-webhook 
                            {--type=articles : Webhook type: articles, customers}
                            {--event= : Event type (default: article.updated or client.updated)}
                            {--article-id= : Article ID to use (articles only)}
                            {--client-id= : Client ID to use (customers only)}
                            {--url= : Webhook URL override}';

    protected $description = 'Test webhook endpoint by simulating a Robaws webhook event';

    public function handle(): int
    {
        $type = $this->option('type');

        if ($type === 'customers') {
            return $this->testCustomerWebhook();
        }

        return $this->testArticleWebhook();
    }

    private function testArticleWebhook(): int
    {
        $event = $this->option('event') ?: 'article.updated';
        $articleId = $this->option('article-id');
        $url = $this->option('url') ?? rtrim(config('app.url'), '/') . '/api/webhooks/robaws/articles';
        $provider = 'robaws';

        if ($articleId) {
            $article = RobawsArticleCache::where('robaws_article_id', $articleId)->first();
            if (!$article) {
                $this->error("Article with Robaws ID {$articleId} not found");
                return 1;
            }
            $articleData = $this->articleToPayload($article);
        } else {
            $article = RobawsArticleCache::inRandomOrder()->first();
            $articleData = $article ? $this->articleToPayload($article) : $this->fakeArticlePayload();
        }

        $timestamp = time();
        $payload = [
            'event' => $event,
            'id' => 'webhook-test-' . $timestamp,
            'data' => $articleData,
        ];

        return $this->sendAndVerify($payload, $url, $provider, $timestamp, function () use ($articleData) {
            $updated = RobawsArticleCache::where('robaws_article_id', $articleData['id'])->first();
            if ($updated) {
                $this->info("Article cache updated (ID: {$updated->id})");
            }
        });
    }

    private function testCustomerWebhook(): int
    {
        $event = $this->option('event') ?: 'client.updated';
        $clientId = $this->option('client-id');
        $url = $this->option('url') ?? rtrim(config('app.url'), '/') . '/api/webhooks/robaws/customers';
        $provider = 'robaws_customers';

        if ($clientId) {
            $customer = RobawsCustomerCache::where('robaws_client_id', $clientId)->first();
            if (!$customer) {
                $this->error("Customer with Robaws client ID {$clientId} not found");
                return 1;
            }
            $customerData = $this->customerToPayload($customer);
        } else {
            $customer = RobawsCustomerCache::inRandomOrder()->first();
            $customerData = $customer ? $this->customerToPayload($customer) : $this->fakeCustomerPayload();
        }

        $timestamp = time();
        $payload = [
            'event' => $event,
            'id' => 'webhook-test-' . $timestamp,
            'data' => $customerData,
        ];

        return $this->sendAndVerify($payload, $url, $provider, $timestamp, function () use ($customerData) {
            $updated = RobawsCustomerCache::where('robaws_client_id', (string) $customerData['id'])->first();
            if ($updated) {
                $this->info("Customer cache updated (ID: {$updated->id})");
            }
        });
    }

    private function articleToPayload(RobawsArticleCache $article): array
    {
        return [
            'id' => $article->robaws_article_id,
            'code' => $article->article_code,
            'name' => $article->article_name,
            'description' => $article->description,
            'unitPrice' => $article->unit_price,
            'isActive' => $article->is_active,
            'lastModifiedAt' => now()->toIso8601String(),
        ];
    }

    private function fakeArticlePayload(): array
    {
        return [
            'id' => 'TEST-' . rand(1000, 9999),
            'code' => 'TEST-CODE-' . rand(100, 999),
            'name' => 'Test Article - Seafreight ACL Export',
            'description' => 'Test webhook simulation article',
            'unitPrice' => 100.00,
            'isActive' => true,
            'lastModifiedAt' => now()->toIso8601String(),
        ];
    }

    private function customerToPayload(RobawsCustomerCache $customer): array
    {
        $payload = [
            'id' => (int) $customer->robaws_client_id,
            'name' => $customer->name,
            'email' => $customer->email,
            'isActive' => $customer->is_active ?? true,
        ];
        if ($customer->pricing_code) {
            $payload['extraFields'] = [
                'PRICING' => ['stringValue' => $customer->pricing_code],
            ];
        }
        return $payload;
    }

    private function fakeCustomerPayload(): array
    {
        $id = rand(90000, 99999);
        return [
            'id' => $id,
            'name' => 'Test Customer ' . $id,
            'email' => 'test-' . $id . '@example.com',
            'isActive' => true,
            'extraFields' => [
                'PRICING' => ['stringValue' => 'A'],
            ],
        ];
    }

    private function sendAndVerify(array $payload, string $url, string $provider, int $timestamp, callable $onSuccess): int
    {
        $payloadJson = json_encode($payload);

        $config = DB::table('webhook_configurations')
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();

        if (!$config) {
            $this->warn("No active webhook configuration found for {$provider}");
            $this->line('Sending webhook WITHOUT signature (will be rejected)');
            $secret = null;
        } else {
            $this->info("Found webhook secret for {$provider}, will sign request");
            $secret = $config->secret;
        }

        $signature = null;
        if ($secret) {
            $signedPayload = $timestamp . '.' . $payloadJson;
            $hash = hash_hmac('sha256', $signedPayload, $secret);
            $signature = "t={$timestamp},v1={$hash}";
        }

        $this->info("Testing Webhook Endpoint");
        $this->line("URL: {$url}");
        $this->line("Event: {$payload['event']}");
        $this->line("Payload ID: {$payload['id']}");
        $this->newLine();
        $this->line('Sending webhook...');

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Robaws-Signature' => $signature ?? 'test-signature',
            ])->post($url, $payload);

            $this->newLine();

            if (!$response->successful()) {
                $this->error('Webhook request failed');
                $this->line("Status: {$response->status()}");
                $this->line("Response: {$response->body()}");
                return 1;
            }

            $this->info('Webhook sent successfully');
            $this->line("Status: {$response->status()}");
            $this->line("Response: {$response->body()}");

            $this->newLine();
            $this->line('Checking webhook logs...');

            $log = \App\Models\RobawsWebhookLog::where('robaws_id', 'webhook-test-' . $timestamp)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($log) {
                $this->info("Webhook log created (ID: {$log->id})");
                $this->line("Status: {$log->status}");
                if ($log->status === 'failed') {
                    $this->error("Error: {$log->error_message}");
                } elseif ($log->status === 'processed') {
                    $this->info('Webhook processed successfully');
                    $onSuccess();
                }
            } else {
                $this->warn('No webhook log found (signature may have been rejected)');
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Exception: ' . $e->getMessage());
            return 1;
        }
    }
}

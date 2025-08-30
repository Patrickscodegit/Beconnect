<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ExploreRobawsApi extends Command
{
    protected $signature = 'robaws:explore';
    protected $description = 'Explore Robaws API endpoints and authentication';

    public function handle()
    {
        $this->info('🔍 Exploring Robaws API...');
        $this->newLine();

        $baseUrl = config('services.robaws.base_url');
        $apiKey = config('services.robaws.api_key');
        $apiSecret = config('services.robaws.api_secret');

        $this->info("Base URL: {$baseUrl}");
        $this->info("API Key: " . substr($apiKey, 0, 10) . "...");
        $this->info("API Secret: " . substr($apiSecret, 0, 10) . "...");
        $this->newLine();

        // Try to access root API endpoint
        $this->info('📡 Testing root API endpoint...');
        $endpoints = [
            '/api',
            '/api/v1',
            '/api/docs',
            '/api/status',
            '/api/health',
            '/api/info',
            '/.well-known/api',
        ];

        foreach ($endpoints as $endpoint) {
            $this->line("Testing: {$baseUrl}{$endpoint}");
            
            try {
                $response = Http::timeout(10)->get($baseUrl . $endpoint);
                $status = $response->status();
                
                if ($status === 200) {
                    $this->info("✅ {$endpoint} - Status: {$status}");
                    $body = $response->body();
                    if (strlen($body) < 500) {
                        $this->line("Response: " . $body);
                    } else {
                        $this->line("Response (truncated): " . substr($body, 0, 200) . "...");
                    }
                } elseif ($status === 401 || $status === 403) {
                    $this->warn("🔒 {$endpoint} - Status: {$status} (Auth required)");
                } elseif ($status === 404) {
                    $this->line("❌ {$endpoint} - Status: {$status} (Not found)");
                } else {
                    $this->warn("⚠️  {$endpoint} - Status: {$status}");
                }
            } catch (\Exception $e) {
                $this->error("💥 {$endpoint} - Error: " . $e->getMessage());
            }
            
            $this->newLine();
        }

        // Try different authentication methods on /api endpoint
        $this->info('🔐 Testing authentication methods on /api...');
        
        $authMethods = [
            'No Auth' => [],
            'Bearer Token' => ['Authorization' => "Bearer {$apiKey}"],
            'API Key Header' => ['X-API-Key' => $apiKey],
            'API Key + Secret' => ['X-API-Key' => $apiKey, 'X-API-Secret' => $apiSecret],
            'Basic Auth' => ['Authorization' => 'Basic ' . base64_encode($apiKey . ':' . $apiSecret)],
            'Custom Headers' => ['robaws-api-key' => $apiKey, 'robaws-api-secret' => $apiSecret],
        ];

        foreach ($authMethods as $methodName => $headers) {
            $this->line("Testing auth method: {$methodName}");
            
            try {
                $response = Http::timeout(10)
                    ->withHeaders(array_merge($headers, ['Accept' => 'application/json']))
                    ->get($baseUrl . '/api');
                
                $status = $response->status();
                $this->line("Status: {$status}");
                
                if ($status === 200) {
                    $this->info("✅ Success with {$methodName}!");
                    $body = $response->json() ?? $response->body();
                    $this->line("Response: " . json_encode($body, JSON_PRETTY_PRINT));
                }
                
            } catch (\Exception $e) {
                $this->line("Error: " . $e->getMessage());
            }
            
            $this->newLine();
        }

        return Command::SUCCESS;
    }
}

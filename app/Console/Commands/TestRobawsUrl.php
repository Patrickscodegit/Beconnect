<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestRobawsUrl extends Command
{
    protected $signature = 'robaws:test-url';
    protected $description = 'Test Robaws URL accessibility';

    public function handle()
    {
        $this->info('Testing Robaws URL accessibility...');
        
        $baseUrl = config('services.robaws.base_url');
        $username = config('services.robaws.username');
        $password = config('services.robaws.password');
        $apiKey = config('services.robaws.api_key');
        $apiSecret = config('services.robaws.api_secret');
        
        $this->line("Base URL: {$baseUrl}");
        $this->line("Username: {$username}");
        $this->line("API Key: " . substr($apiKey, 0, 8) . '...');
        
        // Test 1: Simple GET request to base URL
        $this->newLine();
        $this->info('Test 1: Base URL accessibility');
        
        try {
            $response = Http::timeout(10)->get($baseUrl);
            $this->line("Status: {$response->status()}");
            $this->line("Headers: " . json_encode($response->headers()));
            
            if ($response->successful()) {
                $this->info('✅ Base URL accessible');
            } else {
                $this->error('❌ Base URL failed');
            }
        } catch (\Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
        }
        
        // Test 2: Test API endpoint with Basic Auth
        $this->newLine();
        $this->info('Test 2: API endpoint with Basic Auth');
        
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->withBasicAuth($username, $password)
                ->get($baseUrl . '/api/v2/clients?limit=1');
                
            $this->line("Status: {$response->status()}");
            $this->line("Headers: " . json_encode($response->headers()));
            $this->line("Body preview: " . substr($response->body(), 0, 200));
            
            if ($response->successful()) {
                $this->info('✅ API endpoint accessible with Basic Auth');
            } else {
                $this->error('❌ API endpoint failed with Basic Auth');
            }
        } catch (\Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
        }
        
        // Test 3: Test API endpoint with API Key headers
        $this->newLine();
        $this->info('Test 3: API endpoint with API Key headers');
        
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->withHeaders([
                    'X-API-Key' => $apiKey,
                    'X-API-Secret' => $apiSecret,
                ])
                ->get($baseUrl . '/api/v2/clients?limit=1');
                
            $this->line("Status: {$response->status()}");
            $this->line("Headers: " . json_encode($response->headers()));
            $this->line("Body preview: " . substr($response->body(), 0, 200));
            
            if ($response->successful()) {
                $this->info('✅ API endpoint accessible with API Keys');
            } else {
                $this->error('❌ API endpoint failed with API Keys');
            }
        } catch (\Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
        }
        
        return 0;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\RobawsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RobawsDetailedTest extends Command
{
    protected $signature = 'robaws:detailed-test';
    protected $description = 'Test Robaws API with detailed error reporting';

    public function handle()
    {
        $this->info('🔧 Robaws API Diagnostic Report');
        $this->line('Timestamp: ' . now()->toISOString());
        $this->line('Base URL: ' . config('services.robaws.base_url'));
        $this->line('API Key: ' . substr(config('services.robaws.api_key'), 0, 8) . '...');
        $this->newLine();
        
        // Test recommended API endpoints
        $endpoints = [
            ['name' => 'Metadata', 'path' => '/api/v2/metadata'],
            ['name' => 'Clients', 'path' => '/api/v2/clients?limit=1'],
        ];
        
        $supportInfo = [];
        
        foreach ($endpoints as $endpoint) {
            $this->info("Testing {$endpoint['name']} endpoint...");
            
            try {
                $response = Http::baseUrl(config('services.robaws.base_url'))
                    ->withHeaders([
                        'X-API-Key' => config('services.robaws.api_key'),
                        'X-API-Secret' => config('services.robaws.api_secret'),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json'
                    ])
                    ->timeout(15)
                    ->get($endpoint['path']);

                $this->line("Status: {$response->status()} {$response->getReasonPhrase()}");
                
                // Extract key headers for support
                $correlationId = $response->header('x-correlation-id') ?? 
                               $this->extractCorrelationId($response->body());
                $unauthorizedReason = $response->header('x-robaws-unauthorized-reason');
                $serverVersion = $this->extractServerVersion($response->body());
                
                if ($correlationId) {
                    $this->line("Correlation ID: {$correlationId}");
                    $supportInfo[] = "Correlation ID: {$correlationId}";
                }
                
                if ($unauthorizedReason) {
                    $this->error("⚠️  Robaws Reason: {$unauthorizedReason}");
                    $supportInfo[] = "Unauthorized Reason: {$unauthorizedReason}";
                }
                
                if ($serverVersion) {
                    $this->line("Server Version: {$serverVersion}");
                }

                if ($response->successful()) {
                    $this->info('✅ SUCCESS!');
                    $data = $response->json();
                    if (isset($data['data'])) {
                        $this->line('Data available: ' . count($data['data']) . ' items');
                    }
                    return 0; // Exit early on first success
                } else {
                    $this->error('❌ FAILED');
                    if ($unauthorizedReason === 'temp-blocked') {
                        $this->warn('Account is temporarily blocked - contact support');
                    }
                }

            } catch (\Exception $e) {
                $this->error("❌ Exception: {$e->getMessage()}");
            }
            
            $this->newLine();
        }
        
        // Generate support information
        $this->info('📧 Support Information:');
        $this->line('API Key: ' . substr(config('services.robaws.api_key'), 0, 8) . '...');
        $this->line('Issue: API access with API Key authentication');
        $this->line('Contact: support@robaws.be');
        
        if (!empty($supportInfo)) {
            $this->info('Include in support email:');
            foreach ($supportInfo as $info) {
                $this->line("- {$info}");
            }
        }
        
        $this->newLine();
        $this->info('💡 Next Steps:');
        $this->line('1. Email support@robaws.be with the above information');
        $this->line('2. Request removal of temporary API block');
        $this->line('3. Consider creating a dedicated API-only user');
        $this->line('4. Verify web login works with current credentials');
        
        return 1;
    }
    
    private function extractCorrelationId(string $body): ?string
    {
        if (preg_match('/"correlationId":"([^"]+)"/', $body, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    private function extractServerVersion(string $body): ?string
    {
        if (preg_match('/"serverVersion":"([^"]+)"/', $body, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\RobawsClient;
use Illuminate\Console\Command;

class RobawsTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'robaws:test {--create-offer : Create a test offer}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Robaws API connection and optionally create a test offer';

    /**
     * Execute the console command.
     */
    public function handle(RobawsClient $api)
    {
        $this->info('🧪 Testing Robaws API Integration...');
        $this->newLine();
        
        try {
            // 1. Test API connection
            $this->info('1. Testing API connection...');
            $clients = $api->listClients(['limit' => 1]);
            
            $this->info('   ✅ API connection successful!');
            $this->line('   Status: 200');
            $this->line('   Message: Connected successfully via API V2 Clients');
            $this->newLine();

            // 2. Test client search
            $this->info('2. Testing client search...');
            if (!empty($clients['data'])) {
                $this->info('   ✅ Client search successful!');
                $this->line('   Found ' . count($clients['data']) . ' clients.');
            } else {
                $this->info('   ✅ Client search successful!');
                $this->line('   No clients found in Robaws.');
            }
            $this->newLine();

            // 3. Create test offer if requested
            if ($this->option('create-offer') || $this->confirm('Create a test offer in Robaws?', true)) {
                $this->info('3. Creating test offer...');
                
                // Use first client or default to ID 1
                $clientId = $clients['data'][0]['id'] ?? '1';
                
                $testOffer = [
                    'clientId' => (int) $clientId,
                    'name' => 'TEST - Development - ' . now()->format('Y-m-d H:i:s'),
                    'currency' => 'EUR',
                    'status' => 'DRAFT',
                    'extraFields' => [
                        'JSON' => [
                            'stringValue' => json_encode([
                                'test' => true,
                                'timestamp' => now()->toISOString(),
                                'source' => 'Laravel Development Test'
                            ], JSON_PRETTY_PRINT)
                        ]
                    ],
                    'validityDays' => 30,
                    'paymentTermDays' => 30,
                ];

                $offer = $api->createOffer($testOffer);
                
                $this->info('   ✅ Test offer created successfully!');
                $this->line('   Offer ID: ' . ($offer['id'] ?? 'N/A'));
                $this->line('   Offer Number: ' . ($offer['number'] ?? 'N/A'));
                $this->line('   Robaws URL: ' . config('services.robaws.base_url') . '/offers/' . ($offer['id'] ?? ''));
                $this->newLine();
            }

            // 4. Optional document test
            $documentId = $this->ask('Enter a document ID to test with (or press Enter to skip)');
            if ($documentId) {
                $this->info('4. Testing document integration...');
                // This would test the document-to-offer conversion
                $this->line('   Document integration test would go here');
                $this->newLine();
            }

            $this->info('🎉 Robaws integration testing completed!');
            return 0;

        } catch (\Exception $e) {
            $this->error('❌ API test failed: ' . $e->getMessage());
            
            // Provide helpful debugging info
            if (str_contains($e->getMessage(), '401')) {
                $this->warn('Authentication failed. Please check your API key and secret.');
                $this->line('Current config:');
                $this->line('- Base URL: ' . config('services.robaws.base_url'));
                $this->line('- API Key: ' . substr(config('services.robaws.api_key'), 0, 8) . '...');
                $this->line('- Has Secret: ' . (config('services.robaws.api_secret') ? 'Yes' : 'No'));
            } elseif (str_contains($e->getMessage(), '404')) {
                $this->warn('Endpoint not found. The API path might be different.');
            } elseif (str_contains($e->getMessage(), '403')) {
                $this->warn('Forbidden. Check API permissions in Robaws.');
            }
            
            return 1;
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Services\RobawsClient;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use App\Models\Document;
use Illuminate\Console\Command;

class TestRobawsIntegration extends Command
{
    protected $signature = 'robaws:test {--document-id= : Test with specific document ID}';
    protected $description = 'Test Robaws API connection and offer creation';

    public function handle(RobawsClient $robaws, EnhancedRobawsIntegrationService $integration)
    {
        $this->info('🧪 Testing Robaws API Integration...');
        $this->newLine();

        // Test 1: API Connection
        $this->info('1. Testing API connection...');
        $connectionTest = $robaws->testConnection();
        
        if ($connectionTest['success']) {
            $this->info('   ✅ API connection successful!');
            $this->line("   Status: {$connectionTest['status']}");
            $this->line("   Message: {$connectionTest['message']}");
        } else {
            $this->error('   ❌ API connection failed!');
            $this->line("   Status: {$connectionTest['status']}");
            $this->line("   Error: {$connectionTest['message']}");
            return 1;
        }
        $this->newLine();

        // Test 2: List Clients
        $this->info('2. Testing client search...');
        try {
            $clients = $robaws->searchClients(['limit' => 3]);
            $this->info('   ✅ Client search successful!');
            
            if (!empty($clients['data'])) {
                $this->table(['ID', 'Name', 'Email'], array_map(function ($client) {
                    return [
                        $client['id'] ?? 'N/A',
                        $client['name'] ?? 'N/A',
                        $client['email'] ?? 'N/A'
                    ];
                }, array_slice($clients['data'], 0, 3)));
            } else {
                $this->line('   No clients found in Robaws.');
            }
        } catch (\Exception $e) {
            $this->error('   ❌ Client search failed: ' . $e->getMessage());
        }
        $this->newLine();

        // Test 3: Create Test Offer
        if ($this->confirm('3. Create a test offer in Robaws?', true)) {
            try {
                $testPayload = [
                    'clientId' => $clients['data'][0]['id'] ?? 1,
                    'name' => 'TEST OFFER - Beconnect Integration - ' . now()->format('Y-m-d H:i:s'),
                    'currency' => 'EUR',
                    'status' => 'DRAFT',
                    'validityDays' => 30,
                    'extraFields' => [
                        'JSON' => [
                            'stringValue' => json_encode([
                                'test' => true,
                                'created_at' => now()->toIso8601String(),
                                'source' => 'Beconnect Integration Test',
                                'consignee' => [
                                    'name' => 'Test Company',
                                    'address' => 'Test Address 123',
                                    'contact' => 'test@example.com'
                                ],
                                'container' => [
                                    'number' => 'TEST1234567',
                                    'type' => '20ft'
                                ],
                                'ports' => [
                                    'origin' => 'Shanghai',
                                    'destination' => 'Antwerp'
                                ]
                            ], JSON_PRETTY_PRINT)
                        ]
                    ],
                    'lineItems' => [
                        [
                            'type' => 'LINE',
                            'description' => 'Test Freight - Shanghai to Antwerp',
                            'quantity' => 1,
                            'unitPrice' => 1500.00,
                            'taxRate' => 21
                        ]
                    ]
                ];
                
                $offer = $robaws->createOffer($testPayload);
                $this->info('   ✅ Test offer created successfully!');
                $this->line('   Offer ID: ' . ($offer['id'] ?? 'unknown'));
                $this->line('   Offer Number: ' . ($offer['number'] ?? 'N/A'));
                $this->line('   Robaws URL: ' . config('services.robaws.base_url') . '/offers/' . ($offer['id'] ?? ''));
                
            } catch (\Exception $e) {
                $this->error('   ❌ Test offer creation failed: ' . $e->getMessage());
            }
        }
        $this->newLine();

        // Test 4: Test with Real Document
        $documentId = $this->option('document-id');
        if (!$documentId) {
            $documentId = $this->ask('4. Enter a document ID to test with (or press Enter to skip)');
        }

        if ($documentId) {
            $this->info("4. Testing with document ID: {$documentId}");
            
            $document = Document::find($documentId);
            if (!$document) {
                $this->error('   ❌ Document not found');
                return 1;
            }

            if (empty($document->extraction_data)) {
                $this->warn('   ⚠️  Document has no extraction data');
                $this->line('   Run: php artisan ai:test-extraction first');
                return 1;
            }

            try {
                $offer = $integration->createOfferFromDocument($document);
                
                if ($offer) {
                    $this->info('   ✅ Document offer created successfully!');
                    $this->line('   Offer ID: ' . ($offer['id'] ?? 'unknown'));
                    $this->line('   Robaws URL: ' . config('services.robaws.base_url') . '/offers/' . ($offer['id'] ?? ''));
                } else {
                    $this->error('   ❌ Failed to create offer from document');
                }
                
            } catch (\Exception $e) {
                $this->error('   ❌ Document offer creation failed: ' . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info('🎉 Robaws integration testing completed!');
        
        return 0;
    }
}

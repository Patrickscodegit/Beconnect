<?php

namespace App\Console\Commands;

use App\Services\RobawsClient;
use Illuminate\Console\Command;

class TestRobawsApi extends Command
{
    protected $signature = 'robaws:test';
    protected $description = 'Test Robaws API connection and authentication';

    public function handle()
    {
        $this->info('Testing Robaws API connection...');
        
        try {
            $client = new RobawsClient();
            
            $this->info('Configuration:');
            $this->line('- Base URL: ' . config('services.robaws.base_url'));
            $this->line('- Username: ' . config('services.robaws.username'));
            $this->line('- API Key: ' . substr(config('services.robaws.api_key'), 0, 8) . '...');
            
            $this->newLine();
            $this->info('Testing connection...');
            
            $result = $client->testConnection();
            
            if ($result['success']) {
                $this->info('✅ Connection successful!');
                $this->line('Status: ' . $result['status']);
                $this->line('Message: ' . $result['message']);
                
                if (isset($result['endpoint'])) {
                    $this->line('Working endpoint: ' . $result['endpoint']);
                }
                
                if (!empty($result['data'])) {
                    $this->newLine();
                    $this->info('Sample data:');
                    $this->line(json_encode($result['data'], JSON_PRETTY_PRINT));
                }
                
                // Test listing clients
                $this->newLine();
                $this->info('Testing client listing...');
                
                try {
                    $clients = $client->listClients(['limit' => 5]);
                    $this->info('✅ Client listing successful!');
                    $this->line('Sample clients: ' . json_encode($clients, JSON_PRETTY_PRINT));
                } catch (\Exception $e) {
                    $this->error('❌ Client listing failed: ' . $e->getMessage());
                }
                
            } else {
                $this->error('❌ Connection failed!');
                $this->line('Status: ' . $result['status']);
                $this->line('Message: ' . $result['message']);
                
                // Check for temp-blocked status
                if (isset($result['all_tests'])) {
                    $this->newLine();
                    $this->info('Detailed test results:');
                    
                    foreach ($result['all_tests'] as $test) {
                        $this->line("- {$test['test']}: " . ($test['success'] ? '✅' : '❌'));
                        
                        if (!$test['success']) {
                            if (isset($test['headers']['X-Robaws-Unauthorized-Reason'])) {
                                $reason = $test['headers']['X-Robaws-Unauthorized-Reason'][0] ?? 'unknown';
                                $this->warn("  Unauthorized reason: {$reason}");
                                
                                if ($reason === 'temp-blocked') {
                                    $this->newLine();
                                    $this->error('🚨 ACCOUNT TEMPORARILY BLOCKED');
                                    $this->warn('Your Robaws account appears to be temporarily blocked from API access.');
                                    $this->warn('This could be due to:');
                                    $this->warn('- Rate limiting');
                                    $this->warn('- API access not enabled for your account');
                                    $this->warn('- Account configuration issues');
                                    $this->newLine();
                                    $this->info('Next steps:');
                                    $this->info('1. Contact Robaws support to enable API access');
                                    $this->info('2. Verify your account has API permissions');
                                    $this->info('3. Check if there are API usage limits');
                                    $this->info('4. Wait and try again later if it\'s rate limiting');
                                }
                            }
                            
                            if (isset($test['error'])) {
                                $this->line("  Error: {$test['error']}");
                            }
                        }
                    }
                }
                
                if (!empty($result['data'])) {
                    $this->newLine();
                    $this->info('Response details:');
                    $this->line(json_encode($result['data'], JSON_PRETTY_PRINT));
                }
            }
            
        } catch (\Exception $e) {
            $this->error('❌ Test failed with exception: ' . $e->getMessage());
            $this->line('Trace: ' . $e->getTraceAsString());
        }
        
        $this->newLine();
        $this->info('🏁 Test complete. Next steps:');
        $this->info('1. If temp-blocked: Contact Robaws support to enable API access');
        $this->info('2. If successful: Use POST /robaws/offers to create offers');
        $this->info('3. Use POST /documents/{id}/robaws-offer to send documents');
        
        return 0;
    }
}

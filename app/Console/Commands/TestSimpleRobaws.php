<?php

namespace App\Console\Commands;

use App\Services\RobawsClient;
use Illuminate\Console\Command;

class TestSimpleRobaws extends Command
{
    protected $signature = 'robaws:simple-test';
    protected $description = 'Test Robaws API connection using the main RobawsClient';

    public function handle()
    {
        $this->info('🔧 Testing Robaws Integration...');
        $this->newLine();

        try {
            $client = new RobawsClient();

            // Test connection
            $this->info('🔌 Testing API Connection...');
            $connectionResult = $client->testConnection();

            if ($connectionResult['success']) {
                $this->info('✅ Connection successful!');
                $this->line('Method: ' . ($connectionResult['method'] ?? 'Unknown'));
                if (isset($connectionResult['data'])) {
                    $this->line('Response: ' . json_encode($connectionResult['data'], JSON_PRETTY_PRINT));
                }
            } else {
                $this->error('❌ Connection failed: ' . ($connectionResult['message'] ?? 'Unknown error'));
                $this->line('Status: ' . ($connectionResult['status'] ?? 'N/A'));
            }

            $this->newLine();

        } catch (\Exception $e) {
            $this->error('❌ Test failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->info('🏁 Robaws API test completed.');
        $this->line('💡 For complete document integration testing, use: php artisan robaws:test-simple');

        return Command::SUCCESS;
    }
}

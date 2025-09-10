<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckClientData extends Command
{
    protected $signature = 'test:check-client {client_id}';
    protected $description = 'Check client data in Robaws';

    public function handle()
    {
        $clientId = $this->argument('client_id');
        
        try {
            $apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
            
            // Get the client data directly from Robaws API
            $client = $apiClient->getClientById($clientId);
            
            if ($client) {
                $this->info("Client {$clientId} data from Robaws:");
                $this->info("Name: " . ($client['name'] ?? 'N/A'));
                $this->info("Email: " . ($client['email'] ?? 'N/A'));
                $this->info("Phone: " . ($client['tel'] ?? 'N/A'));
                $this->info("Mobile: " . ($client['gsm'] ?? 'N/A'));
                $this->info("VAT: " . ($client['vatNumber'] ?? 'N/A'));
                $this->info("Website: " . ($client['website'] ?? 'N/A'));
                
                if (!empty($client['address'])) {
                    $addr = $client['address'];
                    $this->info("Address:");
                    $this->info("  Street: " . ($addr['street'] ?? 'N/A'));
                    $this->info("  City: " . ($addr['city'] ?? 'N/A'));
                    $this->info("  Postal Code: " . ($addr['postalCode'] ?? 'N/A'));
                    $this->info("  Country: " . ($addr['country'] ?? 'N/A'));
                }
                
                $this->info("\n✅ Client update verification:");
                $this->info("VAT updated: " . (($client['vatNumber'] === 'BE0437311533') ? 'YES' : 'NO'));
                $this->info("Website updated: " . (($client['website'] === 'www.armos.be') ? 'YES' : 'NO'));
                
            } else {
                $this->error("Client {$clientId} not found!");
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
        
        return 0;
    }
}

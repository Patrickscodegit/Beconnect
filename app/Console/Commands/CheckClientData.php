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
                $this->info("Raw client data:");
                $this->info(json_encode($client, JSON_PRETTY_PRINT));
                $this->info("\n" . str_repeat('=', 50) . "\n");
                
                $this->info("Client {$clientId} data from Robaws:");
                $this->info("Name: " . ($client['name'] ?? 'N/A'));
                $this->info("Email: " . ($client['email'] ?? 'N/A'));
                $this->info("Phone: " . ($client['tel'] ?? 'N/A'));
                $this->info("Mobile: " . ($client['gsm'] ?? 'N/A'));
                $this->info("VAT: " . ($client['vatNumber'] ?? $client['vat'] ?? 'N/A'));
                $this->info("Website: " . ($client['website'] ?? 'N/A'));
                
                // Check for address data in both nested and root level formats
                $this->info("Address:");
                if (!empty($client['address'])) {
                    $addr = $client['address'];
                    $this->info("  Street: " . ($addr['street'] ?? 'N/A'));
                    $this->info("  City: " . ($addr['city'] ?? 'N/A'));
                    $this->info("  Postal Code: " . ($addr['postalCode'] ?? 'N/A'));
                    $this->info("  Country: " . ($addr['country'] ?? 'N/A'));
                } else {
                    // Check root level address fields
                    $this->info("  Street: " . ($client['street'] ?? 'N/A'));
                    $this->info("  City: " . ($client['city'] ?? 'N/A'));
                    $this->info("  Postal Code: " . ($client['postalCode'] ?? 'N/A'));
                    $this->info("  Country: " . ($client['country'] ?? 'N/A'));
                }
                
                $this->info("\n✅ Client update verification:");
                $vatNumber = $client['vatNumber'] ?? $client['vat'] ?? null;
                $this->info("VAT updated: " . (($vatNumber === 'BE0437311533') ? 'YES' : 'NO'));
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

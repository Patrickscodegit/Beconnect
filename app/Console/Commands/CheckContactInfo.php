<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckContactInfo extends Command
{
    protected $signature = 'test:check-contact {client_id}';
    protected $description = 'Check contact information for a client';

    public function handle()
    {
        $clientId = $this->argument('client_id');
        
        try {
            $apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
            
            // Try to find the contact by email first
            $contact = $apiClient->findContactByEmail('nancy@armos.be');
            
            if ($contact) {
                $this->info("=== CONTACT FOR nancy@armos.be ===");
                $this->info("Contact ID: " . ($contact['id'] ?? 'unknown'));
                $this->info("  First Name (name): " . ($contact['name'] ?? 'N/A'));
                $this->info("  Last Name (surname): " . ($contact['surname'] ?? 'N/A'));
                $this->info("  Email: " . ($contact['email'] ?? 'N/A'));
                $this->info("  Phone (tel): " . ($contact['tel'] ?? 'N/A'));
                $this->info("  Mobile (gsm): " . ($contact['gsm'] ?? 'N/A'));
                $this->info("  Function: " . ($contact['function'] ?? 'N/A'));
                $this->info("  Primary: " . (($contact['isPrimary'] ?? false) ? 'YES' : 'NO'));
                $this->info("  Client ID: " . ($contact['clientId'] ?? 'N/A'));
            } else {
                $this->info("No contact found for nancy@armos.be");
            }
            
            // Also try to get client data with contacts included
            $client = $apiClient->getClientById($clientId, ['contacts']);
            
            if ($client && !empty($client['contacts'])) {
                $this->info("\n=== ALL CONTACTS FOR CLIENT {$clientId} ===");
                foreach ($client['contacts'] as $contact) {
                    $this->info("Contact ID: " . ($contact['id'] ?? 'unknown'));
                    $this->info("  First Name (name): " . ($contact['name'] ?? 'N/A'));
                    $this->info("  Last Name (surname): " . ($contact['surname'] ?? 'N/A'));
                    $this->info("  Email: " . ($contact['email'] ?? 'N/A'));
                    $this->info("  Phone (tel): " . ($contact['tel'] ?? 'N/A'));
                    $this->info("  Mobile (gsm): " . ($contact['gsm'] ?? 'N/A'));
                    $this->info("  Function: " . ($contact['function'] ?? 'N/A'));
                    $this->info("  Primary: " . (($contact['isPrimary'] ?? false) ? 'YES' : 'NO'));
                    $this->info("");
                }
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
        
        return 0;
    }
}

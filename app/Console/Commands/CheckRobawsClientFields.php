<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Export\Clients\RobawsApiClient;

class CheckRobawsClientFields extends Command
{
    protected $signature = 'test:robaws-client-fields {client_id}';
    protected $description = 'Check Robaws client fields and contact structure';

    public function handle()
    {
        $clientId = $this->argument('client_id');
        
        $this->info("=== CHECKING ROBAWS CLIENT {$clientId} ===");
        
        try {
            $client = app(RobawsApiClient::class);
            $response = $client->getClient($clientId);
            
            $this->info("Client Response:");
            $this->line(json_encode($response, JSON_PRETTY_PRINT));
            
            $this->info("\n=== CONTACT STRUCTURE CHECK ===");
            if (isset($response['contacts'])) {
                $this->info('Contacts field exists with ' . count($response['contacts']) . ' contacts');
                foreach ($response['contacts'] as $i => $contact) {
                    $this->info("Contact {$i}:");
                    $this->line(json_encode($contact, JSON_PRETTY_PRINT));
                }
            } else {
                $this->warn('No contacts field in response');
            }
            
            $this->info("\n=== CLIENT FIELDS ===");
            $this->line("Name: " . ($response['name'] ?? 'null'));
            $this->line("Email: " . ($response['email'] ?? 'null'));
            $this->line("Tel: " . ($response['tel'] ?? 'null'));
            $this->line("GSM: " . ($response['gsm'] ?? 'null'));
            $this->line("VAT: " . ($response['vatIdNumber'] ?? 'null'));
            
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->error('Trace: ' . $e->getTraceAsString());
        }
    }
}

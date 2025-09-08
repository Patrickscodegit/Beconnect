<?php

use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Support\Facades\Log;

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = app(RobawsApiClient::class);

echo "Adding missing contacts to existing Smitma clients...\n";

// Client 195 (old existing client) and Client 4236 (newer client)
$clientIds = [195, 4236];

foreach ($clientIds as $clientId) {
    echo "\n=== Working on Client {$clientId} ===\n";
    
    try {
        // Contact data for Marga Mennen from Smitma
        $margaContact = [
            'first_name' => 'Marga',
            'last_name' => 'Mennen',
            'email' => 'sales@smitma.com',
            'phone' => '+31773999840',
            'function' => 'Sales Representative',
            'is_primary' => true,
            'receives_quotes' => true,
        ];

        echo "Creating contact for Marga Mennen...\n";
        $contactResult = $client->createClientContact($clientId, $margaContact);
        
        if ($contactResult) {
            echo "✓ Contact created successfully:\n";
            echo "  Contact ID: {$contactResult['id']}\n";
            echo "  Name: {$contactResult['name']} {$contactResult['surname']}\n";
            echo "  Email: {$contactResult['email']}\n";
            echo "  Phone: {$contactResult['tel']}\n";
        } else {
            echo "✗ Contact creation failed\n";
            
            // Check if contact already exists
            try {
                $response = $client->getHttpClient()->get("/api/v2/clients/{$clientId}/contacts");
                if ($response->successful()) {
                    $existingContacts = $response->json();
                    echo "Existing contacts for this client:\n";
                    foreach (($existingContacts['items'] ?? []) as $contact) {
                        echo "- Contact ID: {$contact['id']}, Name: {$contact['name']} {$contact['surname']}, Email: {$contact['email']}\n";
                    }
                }
            } catch (Exception $e) {
                echo "Error checking existing contacts: " . $e->getMessage() . "\n";
            }
        }
        
    } catch (Exception $e) {
        echo "Error processing client {$clientId}: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

echo "\nDone.\n";

<?php

use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Support\Facades\Log;

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = app(RobawsApiClient::class);

echo "Adding missing contact to existing client 4238 (Ebele Efobi)...\n";

try {
    // Contact data for Ebele Efobi based on the extraction logs
    $contactData = [
        'first_name' => 'Ebele',
        'last_name' => 'Efobi',
        'email' => 'ebypounds@gmail.com',
        'phone' => '+23408053040154',
        'is_primary' => true,
        'receives_quotes' => true,
    ];

    // Create the missing contact for client 4238
    echo "Creating contact for client 4238...\n";
    $contactResult = $client->createClientContact(4238, $contactData);
    
    if ($contactResult) {
        echo "✓ Contact created successfully:\n";
        echo json_encode($contactResult, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "✗ Contact creation failed\n";
        echo "Checking if contact already exists...\n";
        
        // Try to find existing contact by email
        try {
            $existing = $client->getHttpClient()->get('/api/v2/contacts', [
                'email' => 'ebypounds@gmail.com',
                'include' => 'client',
                'page' => 0,
                'size' => 5
            ])->throw()->json();
            
            echo "Found contacts with this email:\n";
            foreach (($existing['items'] ?? []) as $contact) {
                echo "- Contact ID: {$contact['id']}, Client ID: {$contact['clientId']}, Name: {$contact['name']} {$contact['surname']}\n";
            }
            
        } catch (Exception $e) {
            echo "Error checking existing contacts: " . $e->getMessage() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\nDone.\n";

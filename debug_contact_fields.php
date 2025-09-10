<?php

require_once __DIR__ . '/bootstrap/app.php';

$app = Illuminate\Foundation\Application::getInstance();
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== ROBAWS CONTACT FIELD INSPECTION ===\n\n";

$apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);

// Get contact by email to see current structure
echo "1. Current contact structure for nancy@armos.be:\n";
$contact = $apiClient->findContactByEmail('nancy@armos.be');

if ($contact) {
    echo "Contact found:\n";
    print_r($contact);
    
    // Check if there are contacts associated with this client
    echo "\n2. Getting client details to see contacts:\n";
    if (isset($contact['id'])) {
        $clientDetails = $apiClient->getClientById($contact['id']);
        echo "Client details:\n";
        print_r($clientDetails);
    }
} else {
    echo "No contact found for nancy@armos.be\n";
}

echo "\n=== END INSPECTION ===\n";

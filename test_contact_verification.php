<?php

use App\Services\Export\Clients\RobawsApiClient;

echo "Verifying contact fields after creation...\n\n";

$client = new RobawsApiClient();

// First, get the contact we just created to verify what fields exist
echo "Fetching contact ID 5387 to check field mapping...\n";

try {
    // Fetch the contact directly to see what fields are returned
    $contact = $client->getHttpClient()
        ->get("/api/v2/clients/4237/contacts/5387")
        ->throw()
        ->json();
    
    echo "Raw contact response:\n";
    echo json_encode($contact, JSON_PRETTY_PRINT) . "\n\n";
    
    // Check all name-related fields
    echo "Name field analysis:\n";
    echo "- id: " . ($contact['id'] ?? 'N/A') . "\n";
    echo "- title: " . ($contact['title'] ?? 'N/A') . "\n";
    echo "- name: " . ($contact['name'] ?? 'N/A') . "\n";
    echo "- firstName: " . ($contact['firstName'] ?? 'N/A') . "\n";
    echo "- forename: " . ($contact['forename'] ?? 'N/A') . "\n";
    echo "- surname: " . ($contact['surname'] ?? 'N/A') . "\n";
    echo "- lastName: " . ($contact['lastName'] ?? 'N/A') . "\n";
    echo "- position: " . ($contact['position'] ?? 'N/A') . "\n";
    echo "- function: " . ($contact['function'] ?? 'N/A') . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// Test with a new contact to see what gets populated
echo "\n" . str_repeat("=", 50) . "\n";
echo "Testing new contact creation with verbose field check...\n\n";

$testContact = [
    'first_name' => 'TestFirstName',
    'last_name' => 'TestLastName', 
    'email' => 'test.verbose@example.com',
    'phone' => '+1234567890',
    'function' => 'Test Manager'
];

try {
    $result = $client->createClientContact(4237, $testContact);
    
    if ($result) {
        echo "New contact created: ID " . $result['id'] . "\n";
        echo "Full response:\n";
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
        
        // Immediately fetch it back to see the persisted data
        $fetchedContact = $client->getHttpClient()
            ->get("/api/v2/clients/4237/contacts/" . $result['id'])
            ->throw()
            ->json();
            
        echo "\nFetched back from server:\n";
        echo json_encode($fetchedContact, JSON_PRETTY_PRINT) . "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

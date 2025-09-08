<?php

use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Support\Facades\Log;

echo "Testing separate firstName/surname field mapping...\n\n";

$client = new RobawsApiClient();

// Test data with separate firstName and surname  
$contactData = [
    'first_name' => 'Ebele',
    'last_name' => 'Efobi',
    'email' => 'ebele.separate@efobimotors.com',
    'phone' => '+234 803 456 7890',
    'function' => 'Managing Director',
    'is_primary' => true
];

echo "Contact data:\n";
echo "- First Name: " . $contactData['first_name'] . "\n";
echo "- Last Name: " . $contactData['last_name'] . "\n";
echo "- Email: " . $contactData['email'] . "\n\n";

echo "Expected API payload (firstName/surname separate):\n";
$expectedPayload = [
    'firstName' => $contactData['first_name'],
    'surname' => $contactData['last_name'],
    'email' => $contactData['email'],
    'tel' => $contactData['phone'],
    'function' => $contactData['function'],
    'isPrimary' => $contactData['is_primary'],
    'receivesQuotes' => true,
    'receivesInvoices' => false,
];
echo json_encode($expectedPayload, JSON_PRETTY_PRINT) . "\n\n";

try {
    echo "Creating contact with client ID 4237...\n";
    $result = $client->createClientContact(4237, $contactData);
    
    if ($result) {
        echo "SUCCESS: Contact created with separate firstName/surname!\n";
        echo "Contact ID: " . ($result['id'] ?? 'N/A') . "\n";
        
        // Check if firstName and surname are in the response
        if (isset($result['firstName'])) {
            echo "firstName in response: " . $result['firstName'] . "\n";
        }
        if (isset($result['surname'])) {
            echo "surname in response: " . $result['surname'] . "\n";
        }
        
        echo "\nFull response:\n" . json_encode($result, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "ERROR: Contact creation failed\n";
    }
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

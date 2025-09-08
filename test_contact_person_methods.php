<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Export\Clients\RobawsApiClient;

echo "=== Contact Person Resolution Test ===\n\n";

// Test the enhanced contact person methods directly
$apiClient = app(RobawsApiClient::class);

echo "Testing contact person resolution methods...\n\n";

// Test 1: Find or create client contact ID
$testClientId = 195; // Known client from previous tests
$testContactData = [
    'email' => 'info@smitma.nl',
    'name' => 'John Smith',
    'first_name' => 'John',
    'last_name' => 'Smith',
    'phone' => '+31 20 1234567'
];

echo "Test 1: Finding or creating contact person for client {$testClientId}\n";
echo "Contact data: " . json_encode($testContactData, JSON_PRETTY_PRINT) . "\n";

try {
    $contactId = $apiClient->findOrCreateClientContactId($testClientId, $testContactData);
    
    if ($contactId) {
        echo "✓ Contact person resolved with ID: {$contactId}\n\n";
        
        // Test 2: Set contact on offer (using a test offer ID)
        echo "Test 2: Testing contact person linking to offer\n";
        
        // Use a known test offer ID (you might need to adjust this)
        $testOfferId = 11776; // Or use an existing offer ID
        
        $success = $apiClient->setOfferContact($testOfferId, $contactId);
        
        if ($success) {
            echo "✓ Successfully linked contact person to offer!\n";
            echo "Contact person John Smith (ID: {$contactId}) should now appear\n";
            echo "in the 'Contact:' dropdown for offer {$testOfferId}\n";
        } else {
            echo "✗ Failed to link contact person to offer\n";
            echo "This could be because offer {$testOfferId} doesn't exist\n";
            echo "or the contact linking API call failed\n";
        }
        
    } else {
        echo "✗ Failed to resolve contact person\n";
    }
    
} catch (Exception $e) {
    echo "✗ Exception during contact person resolution: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Contact Person Test Complete ===\n";
echo "\nThe enhanced contact person linking functionality has been implemented with:\n";
echo "1. findOrCreateClientContactId() - Finds existing or creates new contact persons\n";
echo "2. setOfferContact() - Links contact persons to offers using proper PATCH method\n";
echo "3. Enhanced quotation flow that automatically links contact persons after creation\n\n";
echo "When you create quotations through the normal intake export process,\n";
echo "the contact person should now appear in the Robaws 'Contact:' dropdown.\n";

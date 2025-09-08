<?php

use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Support\Facades\Log;

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = app(RobawsApiClient::class);

// Test data with contact person
$customerData = [
    'name' => 'Contact Test Company',
    'email' => 'contacttest@example.com',
    'phone' => '+1234567890',
    'contact_person' => [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane.doe@example.com',
        'phone' => '+1234567891',
        'function' => 'Manager',
        'is_primary' => true
    ]
];

echo "Starting contact creation test...\n";

try {
    // First, let's test if we can create a client
    echo "1. Creating client...\n";
    $result = $client->createClient($customerData);
    
    if ($result && isset($result['id'])) {
        $clientId = $result['id'];
        echo "✓ Client created with ID: {$clientId}\n";
        
        // Now let's manually test contact creation
        echo "2. Creating contact manually...\n";
        $contactData = $customerData['contact_person'];
        $contactResult = $client->createClientContact($clientId, $contactData);
        
        if ($contactResult) {
            echo "✓ Contact created successfully:\n";
            echo json_encode($contactResult, JSON_PRETTY_PRINT) . "\n";
        } else {
            echo "✗ Contact creation failed\n";
        }
        
        // Let's also check if the automatic creation in createClient worked
        echo "3. Checking logs for automatic contact creation...\n";
        
    } else {
        echo "✗ Client creation failed\n";
        echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\nDone.\n";

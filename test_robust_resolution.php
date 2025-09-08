<?php

require_once __DIR__ . '/vendor/autoload.php';

// Properly bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧪 Testing ROBUST client resolution for Smitma case...\n";
echo "====================================================\n\n";

use App\Services\Export\Clients\RobawsApiClient;

$api = app(RobawsApiClient::class);

// Test the exact hints that would be used for Smitma intake
$hints = [
    'client_name' => 'Sales | Smitma',
    'email' => 'sales@smitma.com',
    'first_name' => 'Sales',
    'last_name' => 'Smitma',
    'contact_email' => 'sales@smitma.com',
    'function' => 'Sales',
    'is_primary' => true,
    'receives_quotes' => true,
    'language' => 'en',
    'currency' => 'EUR',
];

echo "Testing hints:\n";
echo json_encode($hints, JSON_PRETTY_PRINT) . "\n\n";

try {
    echo "🔍 Testing robust email resolution first...\n";
    $emailResult = $api->findClientByEmailRobust('sales@smitma.com');
    if ($emailResult) {
        echo "✅ Email resolution found: Client {$emailResult['id']} - {$emailResult['name']}\n";
    } else {
        echo "❌ Email resolution failed\n";
    }

    echo "\n🔍 Testing full resolution process...\n";
    $resolved = $api->resolveOrCreateClientAndContact($hints);
    
    echo "✅ RESOLUTION COMPLETE:\n";
    echo "  Client ID: {$resolved['id']}\n";
    echo "  Created: " . ($resolved['created'] ? 'YES' : 'NO') . "\n";
    echo "  Source: {$resolved['source']}\n";

    // Verify which client was chosen
    if ($resolved['id'] == 195) {
        echo "\n🎉 SUCCESS: Correctly found original client 195 (Smitma BV)!\n";
    } elseif ($resolved['id'] == 4236) {
        echo "\n⚠️  WARNING: Found duplicate client 4236 instead of original 195\n";
    } else {
        echo "\n❓ UNEXPECTED: Found client {$resolved['id']}\n";
    }

    // Check contacts
    echo "\n📞 Checking contacts for resolved client {$resolved['id']}...\n";
    $client = $api->getClientById((string)$resolved['id'], ['contacts']);
    if ($client && isset($client['contacts'])) {
        echo "Found " . count($client['contacts']) . " contacts:\n";
        foreach ($client['contacts'] as $contact) {
            $firstName = $contact['firstName'] ?? 'N/A';
            $lastName = $contact['lastName'] ?? 'N/A'; 
            $email = $contact['email'] ?? 'N/A';
            echo "  - $firstName $lastName ($email)\n";
        }
    }

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\nTest complete.\n";

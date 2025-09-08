<?php

require_once __DIR__ . '/vendor/autoload.php';

// Properly bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 Investigating client contacts for Smitma...\n";

use App\Services\Export\Clients\RobawsApiClient;

$api = app(RobawsApiClient::class);

// Check contacts for client 195 (original)
echo "\n📞 Contacts for Client 195 (original):\n";
echo "=====================================\n";
try {
    $client195 = $api->getClientById('195', ['contacts']);
    if ($client195 && isset($client195['contacts'])) {
        echo "Found " . count($client195['contacts']) . " contacts:\n";
        foreach ($client195['contacts'] as $contact) {
            $firstName = $contact['firstName'] ?? 'N/A';
            $lastName = $contact['lastName'] ?? 'N/A'; 
            $email = $contact['email'] ?? 'N/A';
            echo "  - $firstName $lastName ($email)\n";
        }
    } else {
        echo "❌ No contacts found or client doesn't exist\n";
        if ($client195) {
            echo "Client data keys: " . implode(', ', array_keys($client195)) . "\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Error getting client 195: " . $e->getMessage() . "\n";
}

// Check contacts for client 4236 (duplicate)
echo "\n📞 Contacts for Client 4236 (duplicate):\n";
echo "========================================\n";
try {
    $client4236 = $api->getClientById('4236', ['contacts']);
    if ($client4236 && isset($client4236['contacts'])) {
        echo "Found " . count($client4236['contacts']) . " contacts:\n";
        foreach ($client4236['contacts'] as $contact) {
            $firstName = $contact['firstName'] ?? 'N/A';
            $lastName = $contact['lastName'] ?? 'N/A'; 
            $email = $contact['email'] ?? 'N/A';
            echo "  - $firstName $lastName ($email)\n";
        }
    } else {
        echo "❌ No contacts found or client doesn't exist\n";
        if ($client4236) {
            echo "Client data keys: " . implode(', ', array_keys($client4236)) . "\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Error getting client 4236: " . $e->getMessage() . "\n";
}

// Try to find the sales@smitma.com contact directly using the API method
echo "\n🔍 Direct contact search for sales@smitma.com:\n";
echo "==============================================\n";
try {
    $contactResult = $api->findContactByEmail('sales@smitma.com');
    if ($contactResult) {
        echo "✅ Found client via contact search:\n";
        echo "  Client ID: {$contactResult['id']}\n";
        echo "  Client Name: {$contactResult['name']}\n";
    } else {
        echo "❌ No contact found with sales@smitma.com\n";
    }
} catch (Exception $e) {
    echo "❌ Error searching contacts: " . $e->getMessage() . "\n";
}

echo "Investigation complete.\n";

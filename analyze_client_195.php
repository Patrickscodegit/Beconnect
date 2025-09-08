<?php

require_once __DIR__ . '/vendor/autoload.php';

// Properly bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 Detailed contact structure analysis...\n";

use App\Services\Export\Clients\RobawsApiClient;

$api = app(RobawsApiClient::class);

// Get client 195 with full contact details
echo "\n📞 Full client 195 data:\n";
echo "========================\n";
try {
    $client195 = $api->getClientById('195', ['contacts']);
    if ($client195) {
        echo "Client Name: {$client195['name']}\n";
        echo "Client Email: " . ($client195['email'] ?? 'N/A') . "\n";
        
        if (isset($client195['contacts'])) {
            echo "\nContacts (" . count($client195['contacts']) . "):\n";
            foreach ($client195['contacts'] as $i => $contact) {
                echo "Contact #" . ($i+1) . ":\n";
                echo json_encode($contact, JSON_PRETTY_PRINT) . "\n\n";
            }
        } else {
            echo "No contacts included\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "Analysis complete.\n";

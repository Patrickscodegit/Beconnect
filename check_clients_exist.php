<?php

require_once __DIR__ . '/vendor/autoload.php';

// Properly bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 Checking if clients 195 and 4236 still exist...\n";
echo "==================================================\n\n";

use App\Services\Export\Clients\RobawsApiClient;

$api = app(RobawsApiClient::class);

echo "Checking client 195 directly...\n";
$client195 = $api->getClientById('195', ['contacts']);
if ($client195) {
    echo "✅ Client 195 exists: {$client195['name']}\n";
    echo "Email: " . ($client195['email'] ?? 'N/A') . "\n";
    echo "Contacts: " . count($client195['contacts'] ?? []) . "\n";
    
    foreach (($client195['contacts'] ?? []) as $contact) {
        $email = $contact['email'] ?? 'N/A';
        $name = trim(($contact['name'] ?? '') . ' ' . ($contact['surname'] ?? ''));
        echo "  - $name ($email)\n";
    }
} else {
    echo "❌ Client 195 not found\n";
}

echo "\nChecking client 4236...\n";
$client4236 = $api->getClientById('4236', ['contacts']);
if ($client4236) {
    echo "✅ Client 4236 exists: {$client4236['name']}\n";  
    echo "Email: " . ($client4236['email'] ?? 'N/A') . "\n";
    echo "Contacts: " . count($client4236['contacts'] ?? []) . "\n";
    
    foreach (($client4236['contacts'] ?? []) as $contact) {
        $email = $contact['email'] ?? 'N/A';
        $name = trim(($contact['name'] ?? '') . ' ' . ($contact['surname'] ?? ''));
        echo "  - $name ($email)\n";
    }
} else {
    echo "❌ Client 4236 not found\n";
}

echo "\nDone.\n";

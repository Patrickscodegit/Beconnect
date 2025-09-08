<?php

require_once __DIR__ . '/vendor/autoload.php';

// Properly bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧪 Testing improved robust email resolution...\n";

use App\Services\Export\Clients\RobawsApiClient;

$api = app(RobawsApiClient::class);

echo "\n1. Testing findClientByEmailRobust:\n";
echo "===================================\n";
$result1 = $api->findClientByEmailRobust('sales@smitma.com');
if ($result1) {
    echo "✅ findClientByEmailRobust found client: {$result1['id']} - {$result1['name']}\n";
} else {
    echo "❌ findClientByEmailRobust failed\n";
}

echo "\n2. Testing scanClientsForEmail:\n";
echo "===============================\n";
$result2 = $api->scanClientsForEmail('sales@smitma.com');
if ($result2) {
    echo "✅ scanClientsForEmail found client: {$result2['id']} - {$result2['name']}\n";
} else {
    echo "❌ scanClientsForEmail failed\n";
}

echo "\n3. Testing complete resolution pipeline:\n";
echo "=======================================\n";
$hints = [
    'email' => 'sales@smitma.com',
    'client_name' => 'Smitma BV',
    'contact_email' => 'sales@smitma.com',
    'first_name' => 'Sales',
    'last_name' => 'Smitma',
    'function' => 'Sales Representative',
];

try {
    $resolved = $api->resolveOrCreateClientAndContact($hints);
    echo "✅ Complete resolution successful:\n";
    echo "   Client ID: {$resolved['id']}\n";
    echo "   Created: " . ($resolved['created'] ? 'Yes' : 'No') . "\n";
    echo "   Source: {$resolved['source']}\n";
    
    if (!$resolved['created'] && $resolved['id'] == 195) {
        echo "🎯 SUCCESS: Found existing client 195 (Smitma BV) - no duplicate created!\n";
    } elseif (!$resolved['created'] && $resolved['id'] == 4236) {
        echo "⚠️  Found client 4236 (duplicate) - should prefer client 195\n";
    } elseif ($resolved['created']) {
        echo "❌ FAILURE: Created new client instead of using existing one\n";
    }
    
} catch (Exception $e) {
    echo "❌ Resolution failed: " . $e->getMessage() . "\n";
}

echo "\nTest complete.\n";

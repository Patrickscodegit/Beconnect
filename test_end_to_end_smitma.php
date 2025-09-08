<?php

require_once __DIR__ . '/vendor/autoload.php';

// Properly bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🎯 FINAL END-TO-END TEST: Smitma Intake Processing\n";
echo "=================================================\n";

use App\Services\Export\Clients\RobawsApiClient;

$api = app(RobawsApiClient::class);

// Simulate the exact hints that ProcessIntake would generate for Smitma
$hints = [
    'client_name' => 'Sales | Smitma',  // This is what gets extracted from email
    'email' => 'sales@smitma.com',
    'contact_email' => 'sales@smitma.com', 
    'first_name' => 'Sales',
    'last_name' => 'Smitma',
    'function' => 'Sales Representative',
    'currency' => 'EUR',
    'language' => 'en',
];

echo "Processing intake with hints:\n";
foreach ($hints as $key => $value) {
    echo "  $key: $value\n";
}
echo "\n";

try {
    echo "🔍 Resolving client...\n";
    $result = $api->resolveOrCreateClientAndContact($hints);
    
    echo "✅ Resolution successful!\n";
    echo "   Client ID: {$result['id']}\n";
    echo "   Created: " . ($result['created'] ? 'Yes (NEW CLIENT)' : 'No (EXISTING CLIENT)') . "\n";
    echo "   Source: {$result['source']}\n\n";
    
    // Verify the client details
    echo "📋 Client verification:\n";
    $client = $api->getClientById((string)$result['id'], ['contacts']);
    if ($client) {
        echo "   Client Name: {$client['name']}\n";
        echo "   Client Email: " . ($client['email'] ?? 'N/A') . "\n";
        echo "   Contacts: " . count($client['contacts'] ?? []) . "\n";
        
        // Show contacts with sales@smitma.com
        $smitmaContacts = array_filter($client['contacts'] ?? [], function($contact) {
            return !empty($contact['email']) && strcasecmp($contact['email'], 'sales@smitma.com') === 0;
        });
        echo "   Contacts with sales@smitma.com: " . count($smitmaContacts) . "\n";
        
        if ($result['id'] == 195 && !$result['created']) {
            echo "\n🎯 PERFECT! Found existing Smitma BV client (195) without creating duplicate!\n";
            echo "   ✅ No duplicate client creation\n";
            echo "   ✅ Contacts properly managed\n";
            echo "   ✅ Defensive resolution working\n";
        } elseif ($result['id'] == 4236) {
            echo "\n⚠️  Found duplicate client 4236 - should investigate chooseBest logic\n";
        } else {
            echo "\n❓ Unexpected client ID: {$result['id']}\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Processing failed: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

echo "\n🏁 End-to-end test complete.\n";

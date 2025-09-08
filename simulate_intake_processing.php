<?php

require_once __DIR__ . '/vendor/autoload.php';

// Properly bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧪 Testing intake processing simulation for Smitma...\n";

use App\Services\Robaws\ClientResolver;
use App\Services\Export\Clients\RobawsApiClient;

// Simulate the exact data that would have been extracted from the email
$extractedData = [
    'contact_persons' => [
        [
            'name' => 'Sales',
            'surname' => 'Smitma', 
            'email' => 'sales@smitma.com',
            'is_primary' => true
        ]
    ],
    'client' => [
        'name' => 'Sales | Smitma',
        'email' => 'sales@smitma.com',
        'type' => 'company'
    ]
];

$contactData = $extractedData['contact_persons'][0];

echo "Extracted Contact Data:\n";
echo json_encode($contactData, JSON_PRETTY_PRINT) . "\n\n";

// Simulate what ProcessIntake would do
$resolver = app(ClientResolver::class);

$hints = array_filter([
    'email' => $contactData['email'],
    'phone' => $contactData['phone'] ?? null,
    'name' => $extractedData['client']['name'],
]);

echo "Resolution hints: " . json_encode($hints) . "\n";

// Test resolution
$result = $resolver->resolve($hints);

if ($result) {
    echo "✅ Found existing client: {$result['id']} (confidence: {$result['confidence']})\n";
    
    // Now test if ensureContactExists would work
    echo "\n🔍 Testing ensureContactExists...\n";
    
    $api = app(RobawsApiClient::class);
    
    // Get existing contacts for this client
    $clientId = (int)$result['id'];
    $client = $api->getClientById((string)$clientId, ['contacts']);
    
    if ($client && isset($client['contacts'])) {
        echo "Existing contacts in client $clientId:\n";
        foreach ($client['contacts'] as $contact) {
            $email = $contact['email'] ?? 'N/A';
            $name = trim(($contact['name'] ?? '') . ' ' . ($contact['surname'] ?? ''));
            echo "  - $name ($email)\n";
        }
        
        // Check if our contact already exists
        $existingContact = array_filter($client['contacts'], function($contact) use ($contactData) {
            return !empty($contact['email']) && strcasecmp($contact['email'], $contactData['email']) === 0;
        });
        
        if (!empty($existingContact)) {
            echo "✅ Contact with {$contactData['email']} already exists - no new contact needed\n";
        } else {
            echo "⚠️  Contact with {$contactData['email']} doesn't exist - would create new contact\n";
        }
    }
} else {
    echo "❌ No existing client found - would create new client\n";
}

echo "\nSimulation complete.\n";

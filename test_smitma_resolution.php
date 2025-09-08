<?php

require_once __DIR__ . '/vendor/autoload.php';

// Properly bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 Testing Smitma client resolution...\n";

use App\Services\Robaws\ClientResolver;
use App\Services\Export\Clients\RobawsApiClient;

$resolver = app(ClientResolver::class);

// Test exact hints that would have been used for Smitma
$hints = [
    'email' => 'sales@smitma.com',
    'name' => 'Sales | Smitma',
];

echo 'Testing resolution with hints: ' . json_encode($hints) . "\n";

$result = $resolver->resolve($hints);
if ($result) {
    echo "✅ Resolved to client: {$result['id']} (confidence: {$result['confidence']})\n";
} else {
    echo "❌ No client found\n";
}

// Now test just with email
echo "\nTesting with email only...\n";
$result = $resolver->resolve(['email' => 'sales@smitma.com']);
if ($result) {
    echo "✅ Email resolved to client: {$result['id']} (confidence: {$result['confidence']})\n";
} else {
    echo "❌ Email resolution failed\n";
}

// Test API client directly
echo "\nTesting RobawsApiClient directly...\n";
$api = app(RobawsApiClient::class);
$client = $api->findClientByEmail('sales@smitma.com');
if ($client) {
    echo "✅ Direct API call found client: {$client['id']} - {$client['name']}\n";
} else {
    echo "❌ Direct API call failed\n";
}

echo "\nTest complete.\n";

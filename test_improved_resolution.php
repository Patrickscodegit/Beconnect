<?php

require_once __DIR__ . '/vendor/autoload.php';

// Properly bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 Testing improved email-based client resolution...\n";

use App\Services\Export\Clients\RobawsApiClient;
use App\Services\Robaws\ClientResolver;

$api = app(RobawsApiClient::class);

echo "Testing improved findContactByEmail for sales@smitma.com...\n";

$result = $api->findContactByEmail('sales@smitma.com');

if ($result) {
    echo "✅ Improved findContactByEmail found client:\n";
    echo "  ID: {$result['id']}\n";
    echo "  Name: {$result['name']}\n";
} else {
    echo "❌ Improved findContactByEmail failed\n";
}

// Now test the full resolver
echo "\nTesting ClientResolver with email-only hints...\n";
$resolver = app(ClientResolver::class);
$result = $resolver->resolve(['email' => 'sales@smitma.com']);

if ($result) {
    echo "✅ ClientResolver found client:\n";
    echo "  ID: {$result['id']}\n";
    echo "  Confidence: {$result['confidence']}\n";
} else {
    echo "❌ ClientResolver failed\n";
}

// Test with combined hints 
echo "\nTesting ClientResolver with combined hints...\n";
$result = $resolver->resolve([
    'email' => 'sales@smitma.com',
    'name' => 'Sales | Smitma'
]);

if ($result) {
    echo "✅ ClientResolver found client:\n";
    echo "  ID: {$result['id']}\n";
    echo "  Confidence: {$result['confidence']}\n";
} else {
    echo "❌ ClientResolver failed\n";
}

echo "\nTest complete.\n";

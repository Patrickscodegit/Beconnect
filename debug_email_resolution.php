<?php

require_once __DIR__ . '/vendor/autoload.php';

// Properly bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 Debugging email-based client resolution...\n";

use App\Services\Export\Clients\RobawsApiClient;

$api = app(RobawsApiClient::class);

echo "Testing findContactByEmail for sales@smitma.com...\n";

// Test the direct contact search
$result = $api->findContactByEmail('sales@smitma.com');

if ($result) {
    echo "✅ findContactByEmail found client:\n";
    echo "  ID: {$result['id']}\n";
    echo "  Name: {$result['name']}\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
} else {
    echo "❌ findContactByEmail failed\n";
    
    echo "\nTesting fallback method...\n";
    
    // Let's manually test the contacts API call
    try {
        // We need to test using reflection since getHttpClient is private
        $reflection = new ReflectionClass($api);
        $method = $reflection->getMethod('getHttpClient');
        $method->setAccessible(true);
        $http = $method->invoke($api);
        
        $res = $http->get('/api/v2/contacts', [
            'email' => 'sales@smitma.com',
            'include' => 'client',
            'page' => 0,
            'size' => 10
        ])->throw()->json();
        
        echo "Raw API response:\n";
        echo json_encode($res, JSON_PRETTY_PRINT) . "\n";
        
    } catch (Exception $e) {
        echo "❌ API call failed: " . $e->getMessage() . "\n";
    }
}

echo "\nDebugging complete.\n";

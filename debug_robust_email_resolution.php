<?php

require_once __DIR__ . '/vendor/autoload.php';

// Properly bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 Debugging robust email resolution step-by-step...\n";
echo "===================================================\n\n";

use App\Services\Export\Clients\RobawsApiClient;

$api = app(RobawsApiClient::class);

$email = 'sales@smitma.com';

echo "Testing email: $email\n\n";

// Test step A: Direct contacts listing
echo "STEP A: Direct contacts API call\n";
echo "--------------------------------\n";
try {
    $reflection = new ReflectionClass($api);
    $method = $reflection->getMethod('getHttpClient');
    $method->setAccessible(true);
    $http = $method->invoke($api);
    
    $res = $http->get('/api/v2/contacts', [
        'email'   => $email,
        'include' => 'client',
        'page'    => 0,
        'size'    => 50,
    ])->throw()->json();

    echo "Total contacts returned: " . count($res['items'] ?? []) . "\n";
    
    foreach (($res['items'] ?? []) as $i => $c) {
        $cEmail = isset($c['email']) ? mb_strtolower(trim($c['email'])) : null;
        $hasClient = !empty($c['client']);
        echo "Contact $i: Email=$cEmail, Has Client=$hasClient\n";
        
        if ($cEmail === mb_strtolower($email) && $hasClient) {
            echo "✅ MATCH FOUND in step A!\n";
            echo "Client: " . json_encode($c['client']) . "\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Step A failed: " . $e->getMessage() . "\n";
}

// Test step B: Clients with include=contacts
echo "\nSTEP B: Clients with include=contacts\n";
echo "------------------------------------\n";
try {
    $res = $http->get('/api/v2/clients', [
        'page' => 0, 'size' => 50, 'sort' => 'name:asc',
        'include' => 'contacts',
    ])->throw()->json();

    echo "Total clients returned: " . count($res['items'] ?? []) . "\n";
    
    foreach (($res['items'] ?? []) as $i => $client) {
        $clientName = $client['name'] ?? 'N/A';
        $contactCount = count($client['contacts'] ?? []);
        
        foreach (($client['contacts'] ?? []) as $c) {
            $cEmail = isset($c['email']) ? mb_strtolower(trim($c['email'])) : null;
            if ($cEmail === mb_strtolower($email)) {
                echo "✅ MATCH FOUND in step B!\n";
                echo "Client: {$client['id']} - $clientName\n";
                echo "Contact email: $cEmail\n";
                return;
            }
        }
        
        if ($contactCount > 0) {
            echo "Client $i ({$client['id']}): $clientName ($contactCount contacts)\n";
        }
    }
    echo "❌ No matches in step B\n";
} catch (Exception $e) {
    echo "❌ Step B failed: " . $e->getMessage() . "\n";
}

echo "\nDebug complete.\n";

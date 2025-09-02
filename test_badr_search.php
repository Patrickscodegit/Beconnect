<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

echo "=== Searching for Badr Algothami ===" . PHP_EOL;

$http = Http::baseUrl(config('services.robaws.base_url'))
    ->acceptJson()
    ->withBasicAuth(config('services.robaws.username'), config('services.robaws.password'));

// Get all clients with larger page size
$response = $http->get('/api/v2/clients', ['pageSize' => 100]);

if ($response->successful()) {
    $data = $response->json();
    $clients = $data['items'] ?? [];
    
    echo "Found " . count($clients) . " clients total" . PHP_EOL;
    echo "Total pages: " . ($data['totalPages'] ?? 1) . PHP_EOL;
    
    $found = false;
    foreach ($clients as $client) {
        $name = $client['name'] ?? '';
        $email = $client['email'] ?? '';
        
        if (stripos($name, 'Badr') !== false || stripos($email, 'badr') !== false) {
            echo "FOUND BADR CLIENT:" . PHP_EOL;
            echo "  Name: " . $name . PHP_EOL;
            echo "  Email: " . $email . PHP_EOL;
            echo "  ID: " . ($client['id'] ?? 'N/A') . PHP_EOL;
            $found = true;
        }
    }
    
    if (!$found) {
        echo "No 'Badr' found in client names or emails." . PHP_EOL;
        echo "This suggests the client is not accessible via the API with current credentials." . PHP_EOL;
    }
} else {
    echo "API request failed: " . $response->status() . PHP_EOL;
    echo "Body: " . $response->body() . PHP_EOL;
}

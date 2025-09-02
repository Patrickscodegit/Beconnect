<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

echo "=== Comprehensive Search for Badr Algothami ===" . PHP_EOL;

$http = Http::baseUrl(config('services.robaws.base_url'))
    ->acceptJson()
    ->withBasicAuth(config('services.robaws.username'), config('services.robaws.password'));

$searchTerms = [
    'Badr Algothami',
    'badr.algothami@gmail.com',
    'badr',
    'algothami',
    'Badr',
    'Algothami'
];

foreach ($searchTerms as $term) {
    echo PHP_EOL . "Searching for: \"$term\"" . PHP_EOL;
    
    $response = $http->get('/api/v2/clients', [
        'search' => $term,
        'size' => 100,
        'page' => 1
    ]);
    
    if ($response->successful()) {
        $data = $response->json();
        $clients = $data['items'] ?? [];
        $totalPages = $data['totalPages'] ?? 1;
        $totalCount = $data['totalCount'] ?? 0;
        
        echo "Results: " . count($clients) . " clients, Total: $totalCount, Pages: $totalPages" . PHP_EOL;
        
        $foundBadr = false;
        foreach ($clients as $client) {
            $name = $client['name'] ?? '';
            $email = $client['email'] ?? '';
            
            if (stripos($name, 'badr') !== false || stripos($email, 'badr') !== false) {
                echo "FOUND BADR: $name ($email) ID: " . ($client['id'] ?? 'N/A') . PHP_EOL;
                $foundBadr = true;
            }
        }
        
        if (!$foundBadr && count($clients) > 0) {
            echo "Sample results: ";
            for ($i = 0; $i < min(3, count($clients)); $i++) {
                echo $clients[$i]['name'] ?? 'N/A';
                if ($i < min(2, count($clients) - 1)) echo ', ';
            }
            echo PHP_EOL;
        }
    } else {
        echo "API Error: " . $response->status() . PHP_EOL;
    }
}

// Try broader search across multiple pages for any clients with "badr" in name or email
echo PHP_EOL . "=== Extended Pagination Search ===" . PHP_EOL;
$totalFound = 0;
$maxPages = 20; // Search first 20 pages

for ($page = 1; $page <= $maxPages; $page++) {
    $response = $http->get('/api/v2/clients', [
        'size' => 100,
        'page' => $page
    ]);
    
    if ($response->successful()) {
        $data = $response->json();
        $clients = $data['items'] ?? [];
        
        if (empty($clients)) break; // No more results
        
        foreach ($clients as $client) {
            $name = $client['name'] ?? '';
            $email = $client['email'] ?? '';
            
            if (stripos($name, 'badr') !== false || stripos($email, 'badr') !== false) {
                echo "FOUND BADR on page $page: $name ($email) ID: " . $client['id'] . PHP_EOL;
                $totalFound++;
            }
        }
        
        if ($page % 5 == 0) {
            echo "Searched page $page..." . PHP_EOL;
        }
    } else {
        break;
    }
}

echo PHP_EOL . "Total Badr matches found across $maxPages pages: $totalFound" . PHP_EOL;

if ($totalFound === 0) {
    echo PHP_EOL . "CONCLUSION: Badr Algothami is not accessible via API search." . PHP_EOL;
    echo "This could be due to:" . PHP_EOL;
    echo "1. Client is in a different tenant/environment" . PHP_EOL;
    echo "2. API access permissions are limited" . PHP_EOL;
    echo "3. Client is archived/inactive and not returned in searches" . PHP_EOL;
    echo "4. Search index doesn't include this client" . PHP_EOL;
    echo "5. Different authentication context between API and UI" . PHP_EOL;
}

<?php

require_once __DIR__ . '/vendor/autoload.php';

// Properly bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 Searching for Smitma BV in client pages...\n";
echo "==============================================\n\n";

use App\Services\Export\Clients\RobawsApiClient;

$api = app(RobawsApiClient::class);

$reflection = new ReflectionClass($api);
$method = $reflection->getMethod('getHttpClient');
$method->setAccessible(true);
$http = $method->invoke($api);

$targetNames = ['smitma', 'smitma bv'];
$targetEmail = 'sales@smitma.com';

// Search through multiple pages to find Smitma
for ($page = 0; $page < 10; $page++) {
    echo "Searching page $page...\n";
    
    try {
        $res = $http->get('/api/v2/clients', [
            'page' => $page, 
            'size' => 100, 
            'sort' => 'name:asc',
            'include' => 'contacts',
        ])->throw()->json();

        $clients = $res['items'] ?? [];
        
        foreach ($clients as $client) {
            $name = mb_strtolower($client['name'] ?? '');
            $id = $client['id'] ?? 'unknown';
            
            // Check if this is a Smitma client by name
            foreach ($targetNames as $target) {
                if (strpos($name, $target) !== false) {
                    echo "✅ FOUND SMITMA CLIENT: {$client['id']} - {$client['name']}\n";
                    
                    // Check contacts
                    $contacts = $client['contacts'] ?? [];
                    echo "  Contacts (" . count($contacts) . "):\n";
                    foreach ($contacts as $contact) {
                        $email = $contact['email'] ?? 'N/A';
                        $contactName = trim(($contact['name'] ?? '') . ' ' . ($contact['surname'] ?? ''));
                        echo "    - $contactName ($email)\n";
                        
                        if (mb_strtolower($email) === mb_strtolower($targetEmail)) {
                            echo "      ✅ EMAIL MATCH FOUND!\n";
                        }
                    }
                    echo "\n";
                }
            }
            
            // Also check if any contact has the target email
            foreach (($client['contacts'] ?? []) as $contact) {
                $email = mb_strtolower($contact['email'] ?? '');
                if ($email === mb_strtolower($targetEmail)) {
                    echo "📧 CLIENT WITH TARGET EMAIL: {$client['id']} - {$client['name']}\n";
                    echo "   Contact: " . trim(($contact['name'] ?? '') . ' ' . ($contact['surname'] ?? '')) . "\n\n";
                }
            }
        }
        
        // Check if we've reached the end
        $totalItems = (int)($res['totalItems'] ?? 0);
        if (($page + 1) * 100 >= $totalItems) {
            echo "Reached end of clients (total: $totalItems)\n";
            break;
        }
        
    } catch (Exception $e) {
        echo "❌ Error on page $page: " . $e->getMessage() . "\n";
        break;
    }
}

echo "\nSearch complete.\n";

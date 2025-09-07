<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 Simple Client Test\n";
echo "====================\n\n";

try {
    $apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
    echo "✅ API Client loaded\n";
    
    echo "Testing client 3473...\n";
    $client = $apiClient->getClientById('3473');
    
    if ($client) {
        echo "✅ Client 3473 found!\n";
        echo "Name: " . ($client['name'] ?? 'N/A') . "\n";
        echo "Email: " . ($client['email'] ?? 'N/A') . "\n\n";
        
        // Now test getting offer 11720
        echo "Testing offer 11720...\n";
        $offerResponse = $apiClient->getOffer('11720');
        
        if ($offerResponse['success']) {
            $offer = $offerResponse['data'];
            echo "✅ Offer 11720 found!\n";
            echo "clientId in offer: " . json_encode($offer['clientId'] ?? null) . "\n";
            echo "client object in offer: " . json_encode($offer['client'] ?? null) . "\n\n";
            
            if (!empty($offer['clientId']) && empty($offer['client'])) {
                echo "🔍 DIAGNOSIS:\n";
                echo "- Offer has clientId: {$offer['clientId']}\n";
                echo "- Offer client object is empty/null\n";
                echo "- Client {$offer['clientId']} exists independently\n";
                echo "- This means the client reference isn't being populated automatically\n\n";
                
                echo "🔧 POSSIBLE SOLUTIONS:\n";
                echo "1. Check if we need to use 'customerId' field instead of 'clientId'\n";
                echo "2. Check if Robaws API requires different client linking approach\n";
                echo "3. Check if we need to include client data in the offer creation payload\n";
                echo "4. Check if there's a separate API call to link client to offer\n";
            }
        } else {
            echo "❌ Failed to get offer 11720\n";
            echo "Error: " . ($offerResponse['error'] ?? 'Unknown') . "\n";
        }
        
    } else {
        echo "❌ Client 3473 not found\n";
        echo "This would explain the linking issue\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

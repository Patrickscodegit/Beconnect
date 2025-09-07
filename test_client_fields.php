<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 Testing Different Client Linking Fields\n";
echo "==========================================\n\n";

try {
    $apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
    
    echo "Testing different payload structures for client linking...\n\n";
    
    // Test 1: clientId as integer
    echo "TEST 1: clientId as integer\n";
    echo "──────────────────────────\n";
    $payload1 = [
        'title' => 'Test 1 - clientId integer',
        'clientId' => 3473,
        'status' => 'draft',
        'items' => []
    ];
    echo "Payload: " . json_encode($payload1, JSON_PRETTY_PRINT) . "\n";
    
    $response1 = $apiClient->createQuotation($payload1);
    if ($response1['success']) {
        $offerId1 = $response1['data']['id'];
        echo "✅ Created quotation: $offerId1\n";
        
        $check1 = $apiClient->getOffer($offerId1);
        if ($check1['success']) {
            $offer1 = $check1['data'];
            echo "clientId: " . json_encode($offer1['clientId'] ?? null) . "\n";
            echo "client: " . json_encode($offer1['client'] ?? null) . "\n";
        }
        echo "Test complete (no cleanup - quotation will remain)\n\n";
    } else {
        echo "❌ Failed: " . ($response1['error'] ?? 'Unknown error') . "\n\n";
    }
    
    // Test 2: customerId instead
    echo "TEST 2: customerId instead of clientId\n";
    echo "─────────────────────────────────────\n";
    $payload2 = [
        'title' => 'Test 2 - customerId',
        'customerId' => 3473,
        'status' => 'draft',
        'items' => []
    ];
    echo "Payload: " . json_encode($payload2, JSON_PRETTY_PRINT) . "\n";
    
    $response2 = $apiClient->createQuotation($payload2);
    if ($response2['success']) {
        $offerId2 = $response2['data']['id'];
        echo "✅ Created quotation: $offerId2\n";
        
        $check2 = $apiClient->getOffer($offerId2);
        if ($check2['success']) {
            $offer2 = $check2['data'];
            echo "clientId: " . json_encode($offer2['clientId'] ?? null) . "\n";
            echo "client: " . json_encode($offer2['client'] ?? null) . "\n";
            echo "customerId: " . json_encode($offer2['customerId'] ?? null) . "\n";
        }
        echo "Test complete (no cleanup - quotation will remain)\n\n";
    } else {
        echo "❌ Failed: " . ($response2['error'] ?? 'Unknown error') . "\n\n";
    }
    
    // Test 3: Both fields
    echo "TEST 3: Both clientId and customerId\n";
    echo "──────────────────────────────────\n";
    $payload3 = [
        'title' => 'Test 3 - Both fields',
        'clientId' => 3473,
        'customerId' => 3473,
        'status' => 'draft',
        'items' => []
    ];
    echo "Payload: " . json_encode($payload3, JSON_PRETTY_PRINT) . "\n";
    
    $response3 = $apiClient->createQuotation($payload3);
    if ($response3['success']) {
        $offerId3 = $response3['data']['id'];
        echo "✅ Created quotation: $offerId3\n";
        
        $check3 = $apiClient->getOffer($offerId3);
        if ($check3['success']) {
            $offer3 = $check3['data'];
            echo "clientId: " . json_encode($offer3['clientId'] ?? null) . "\n";
            echo "client: " . json_encode($offer3['client'] ?? null) . "\n";
            echo "customerId: " . json_encode($offer3['customerId'] ?? null) . "\n";
        }
        echo "Test complete (no cleanup - quotation will remain)\n\n";
    } else {
        echo "❌ Failed: " . ($response3['error'] ?? 'Unknown error') . "\n\n";
    }
    
    echo "🎯 ANALYSIS:\n";
    echo "───────────\n";
    echo "Check which test successfully links the client object!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

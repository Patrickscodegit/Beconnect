<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧪 TESTING ROBAWS CUSTOM FIELD HANDLING\n";
echo "======================================\n\n";

try {
    $robawsClient = app(App\Services\RobawsClient::class);
    
    // Create a simple test offer with known custom fields
    $testPayload = [
        'clientId' => 4161, // Use existing client
        'name' => 'Field Test - ' . date('Y-m-d H:i:s'),
        'currency' => 'EUR',
        'status' => 'DRAFT',
        'validityDays' => 30,
        'paymentTermDays' => 30,
        'notes' => '',
        'lineItems' => [
            [
                'type' => 'LINE',
                'description' => 'Test Line Item',
                'quantity' => 1,
                'unitPrice' => 100,
                'taxRate' => 21,
            ]
        ],
        
        // Add our custom fields
        'JSON' => '{"test": "This is a test JSON field", "timestamp": "' . now()->toISOString() . '"}',
        'Customer' => 'TEST CUSTOMER NAME',
        'Customer reference' => 'TEST-REF-12345',
        'POR' => 'TEST PORT OF RECEIPT',
        'POL' => 'TEST PORT OF LOADING',
        'POD' => 'TEST PORT OF DISCHARGE',
        'CARGO' => 'TEST CARGO DESCRIPTION',
        'Contact' => 'TEST CONTACT INFO',
        'DIM_BEF_DELIVERY' => 'TEST DIMENSIONS',
    ];
    
    echo "📤 Sending test payload to Robaws...\n";
    echo "Payload fields: " . implode(', ', array_keys($testPayload)) . "\n\n";
    
    $result = $robawsClient->createOffer($testPayload);
    
    if ($result && isset($result['id'])) {
        echo "✅ Test offer created successfully!\n";
        echo "Offer ID: " . $result['id'] . "\n";
        echo "Status: " . ($result['status'] ?? 'unknown') . "\n";
        
        // Check if extraFields are in the response
        if (isset($result['extraFields'])) {
            echo "\n📋 Extra fields in response:\n";
            foreach ($result['extraFields'] as $index => $field) {
                if (is_array($field) && isset($field['key'])) {
                    echo "  {$field['key']}: " . ($field['value'] ?? 'EMPTY') . "\n";
                } else {
                    echo "  [{$index}]: " . print_r($field, true) . "\n";
                }
            }
        } else {
            echo "\n❌ No extraFields found in response\n";
        }
        
        echo "\n🔍 You can check offer ID " . $result['id'] . " in Robaws to see if custom fields are populated.\n";
        
    } else {
        echo "❌ Failed to create test offer\n";
        echo "Response: " . print_r($result, true) . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

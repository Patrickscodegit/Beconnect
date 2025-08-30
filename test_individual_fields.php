<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧪 MINIMAL FIELD TEST - ONE BY ONE\n";
echo "==================================\n\n";

$robawsClient = app(App\Services\RobawsClient::class);

// Test each field individually to see which ones work
$testFields = [
    'JSON' => '{"test": "simple json data"}',
    'CARGO' => 'Test Cargo Description',
    'Customer' => 'Test Customer Name',
    'POR' => 'Test Port of Receipt',
    'Contact' => 'Test Contact Info'
];

foreach ($testFields as $fieldName => $fieldValue) {
    echo "Testing field: {$fieldName}\n";
    
    $payload = [
        'clientId' => 4162, // Use existing client
        'name' => "Test {$fieldName} Field - " . date('H:i:s'),
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
        
        // Add only this one field
        $fieldName => $fieldValue
    ];
    
    try {
        $result = $robawsClient->createOffer($payload);
        
        if ($result && isset($result['id'])) {
            echo "✅ Offer created: ID = {$result['id']}\n";
            
            // Check if the field has a value
            $fieldFound = false;
            $fieldHasValue = false;
            
            if (isset($result['extraFields'])) {
                foreach ($result['extraFields'] as $field) {
                    if (isset($field['key']) && $field['key'] === $fieldName) {
                        $fieldFound = true;
                        $value = $field['value'] ?? '';
                        if (!empty($value)) {
                            $fieldHasValue = true;
                            echo "✅ {$fieldName} field has value: " . substr($value, 0, 50) . "\n";
                        } else {
                            echo "❌ {$fieldName} field is empty\n";
                        }
                        break;
                    }
                }
            }
            
            if (!$fieldFound) {
                echo "❓ {$fieldName} field not found in response\n";
            }
            
        } else {
            echo "❌ Failed to create offer\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

echo "=== CONCLUSION ===\n";
echo "This will help us identify:\n";
echo "1. Which fields can accept values\n";
echo "2. If there are any validation rules\n";
echo "3. If the JSON field works differently than others\n";

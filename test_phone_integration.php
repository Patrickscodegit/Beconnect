<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Export\Clients\RobawsApiClient;

echo "Testing enhanced phone number extraction for Armos BV...\n\n";

// Test data matching the Armos BV extraction format
$testData = [
    'customer' => 'Armos BV', 
    'contact_email' => 'nancy@armos.be',
    'concerning' => 'Test Quotation',
    'extraction_data' => [
        'raw_data' => [
            'company' => 'Armos BV',
            'email' => 'nancy@armos.be',
            'phone' => '+32034358657',
            'mobile' => '+32 (0)476 72 02 16',
            'vat' => '0437311533',
            'website' => 'www.armos.be',
            'address' => 'Kapelsesteenweg 611, 2950 Kapellen, Belgium'
        ],
        'contact' => [
            'email' => 'nancy@armos.be',
            'name' => 'Nancy Deckers'
        ]
    ]
];

try {
    $mapper = new RobawsMapper(new RobawsApiClient());
    
    // Test the public toRobawsApiPayload method
    $result = $mapper->toRobawsApiPayload($testData);
    
    echo "=== RESULT STRUCTURE ===\n";
    echo "Keys: " . implode(', ', array_keys($result)) . "\n\n";
    
    if (isset($result['extraFields'])) {
        echo "=== TEMPLATE FIELDS (in extraFields) ===\n";
        $templateFields = ['client', 'clientTel', 'clientGsm', 'clientEmail', 'clientVat', 'clientAddress'];
        foreach ($templateFields as $field) {
            if (isset($result['extraFields'][$field])) {
                echo "$field: " . ($result['extraFields'][$field]['stringValue'] ?? 'no value') . "\n";
            }
        }
        
        echo "\n=== CONTACT FIELDS (in extraFields) ===\n";
        $contactFields = ['CONTACT_NAME', 'CONTACT_EMAIL', 'CONTACT_TEL', 'CONTACT_GSM'];
        foreach ($contactFields as $field) {
            if (isset($result['extraFields'][$field])) {
                echo "$field: " . ($result['extraFields'][$field]['stringValue'] ?? 'no value') . "\n";
            }
        }
        
        echo "\n=== ALL EXTRA FIELDS ===\n";
        foreach ($result['extraFields'] as $key => $value) {
            echo "$key: " . (is_array($value) ? ($value['stringValue'] ?? json_encode($value)) : $value) . "\n";
        }
    } else {
        echo "No extraFields found in result\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

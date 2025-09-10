<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Export\Clients\RobawsApiClient;

echo "Testing contact person phone number update with enhanced logging...\n\n";

// Test data matching the expected structure for toRobawsApiPayload
$testData = [
    'client_id' => 4248,  // Force client ID to ensure contact update runs
    'quotation_info' => [
        'customer' => 'Armos BV',
        'contact_email' => 'nancy@armos.be',
        'concerning' => 'Test Quotation - Contact Phone Fix',
        'project' => 'RO-RO Test',
    ],
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
    ],
    // Add routing data to avoid errors
    'routing' => [
        'por' => 'Antwerp',
        'pol' => 'Antwerp', 
        'pod' => 'Mombasa',
        'fdest' => 'Mombasa'
    ],
    // Add cargo data to avoid errors  
    'cargo_details' => [
        'cargo' => '1 x used Truck'
    ]
];

try {
    $mapper = new RobawsMapper(new RobawsApiClient());
    
    echo "🚀 Starting contact phone update test...\n\n";
    echo "Input data:\n";
    echo "- Customer: {$testData['quotation_info']['customer']}\n";
    echo "- Contact Email: {$testData['quotation_info']['contact_email']}\n";
    echo "- Client ID: {$testData['client_id']}\n";
    echo "- Phone: {$testData['extraction_data']['raw_data']['phone']}\n";
    echo "- Mobile: {$testData['extraction_data']['raw_data']['mobile']}\n\n";
    
    // This should trigger the contact phone update logic
    $result = $mapper->toRobawsApiPayload($testData);
    
    echo "✅ Export completed successfully!\n\n";
    echo "📋 Check the Laravel logs for detailed information:\n";
    echo "   tail -f storage/logs/laravel.log\n\n";
    echo "Look for log entries containing:\n";
    echo "- 'Attempting to update contact person phone numbers'\n";
    echo "- 'Sending contact update to Robaws API'\n";
    echo "- 'Robaws contact update response'\n\n";
    
    echo "🎯 Template fields generated:\n";
    if (isset($result['extraFields']['clientTel'])) {
        echo "- clientTel: " . $result['extraFields']['clientTel']['stringValue'] . "\n";
    }
    if (isset($result['extraFields']['clientGsm'])) {
        echo "- clientGsm: " . $result['extraFields']['clientGsm']['stringValue'] . "\n";
    }
    if (isset($result['extraFields']['CONTACT_TEL'])) {
        echo "- CONTACT_TEL: " . $result['extraFields']['CONTACT_TEL']['stringValue'] . "\n";
    }
    if (isset($result['extraFields']['CONTACT_GSM'])) {
        echo "- CONTACT_GSM: " . $result['extraFields']['CONTACT_GSM']['stringValue'] . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

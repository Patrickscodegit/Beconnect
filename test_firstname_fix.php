<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Export\Clients\RobawsApiClient;

echo "=== Testing First Name Fix ===\n";

// Test data that matches the structure expected by toRobawsApiPayload
$testData = [
    'client_id' => 4248,  // Armos client ID
    'quotation_info' => [
        'customer' => 'Armos BV',
        'contact_email' => 'nancy@armos.be',
        'concerning' => 'Test First Name Fix',
        'project' => 'Name Field Test',
    ],
    'extraction_data' => [
        'raw_data' => [
            'phone' => '+32034358657',
            'mobile' => '+32 (0)476 72 02 16',
        ],
        'contact' => [
            'email' => 'nancy@armos.be',
            'name' => 'Nancy Deckers'  // This should be split into first_name and last_name
        ]
    ],
    // Add required data to avoid errors
    'routing' => [
        'por' => 'Antwerp',
        'pol' => 'Antwerp', 
        'pod' => 'Mombasa',
        'fdest' => 'Mombasa'
    ],
    'cargo_details' => [
        'cargo' => '1 x used BMW'
    ]
];

echo "Input contact name: \"" . $testData['extraction_data']['contact']['name'] . "\"\n";
echo "Expected: first_name = 'Nancy', last_name = 'Deckers'\n\n";

try {
    $mapper = new RobawsMapper(new RobawsApiClient());
    
    echo "🚀 Running mapper with first name fix...\n";
    $result = $mapper->toRobawsApiPayload($testData);
    
    echo "✅ Mapping completed successfully!\n\n";
    
    echo "📋 Check the Laravel logs for contact creation details:\n";
    echo "   tail -f storage/logs/laravel.log | grep -A 10 'Attempting to upsert'\n\n";
    
    echo "The contact should now be created/updated with:\n";
    echo "- Email: nancy@armos.be\n";
    echo "- First Name: Nancy\n";
    echo "- Last Name: Deckers\n";
    echo "- Phone: +32034358657\n";
    echo "- Mobile: +32 (0)476 72 02 16\n\n";
    
    echo "🎯 Verify in Robaws that Nancy Deckers now appears with:\n";
    echo "- First name column populated: 'Nancy'\n";
    echo "- Surname column populated: 'Deckers'\n";
    echo "- Phone numbers maintained\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

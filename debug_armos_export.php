<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Debug the complete export payload for intake 11 (Armos BV)
$intake = App\Models\Intake::find(11);
$mapper = new App\Services\Export\Mappers\RobawsMapper();

echo "=== INTAKE 11 EXPORT DEBUG (ARMOS BV) ===\n\n";

if (!$intake) {
    echo "Intake 11 not found!\n";
    exit(1);
}

echo "Intake customer_name: " . $intake->customer_name . "\n\n";

// Get extraction data
$extractionData = $intake->extraction_data;
echo "1. Extraction data available:\n";
echo "   - Raw data sections: " . implode(', ', array_keys($extractionData['raw_data'] ?? [])) . "\n";
echo "   - Contact data: " . json_encode($extractionData['contact'] ?? [], JSON_PRETTY_PRINT) . "\n\n";

// Test mapper
echo "2. Mapper output:\n";
$mapped = $mapper->mapIntakeToRobaws($intake, $extractionData);
echo "   Quotation info customer: " . ($mapped['quotation_info']['customer'] ?? 'N/A') . "\n";
echo "   Quotation info contact: " . ($mapped['quotation_info']['contact'] ?? 'N/A') . "\n";
echo "   Quotation info email: " . ($mapped['quotation_info']['contact_email'] ?? 'N/A') . "\n\n";

// Test API payload conversion
echo "3. Final API payload structure:\n";
$payload = $mapper->toRobawsApiPayload($mapped);
echo "   Title: " . ($payload['title'] ?? 'N/A') . "\n";
echo "   Contact Email: " . ($payload['contactEmail'] ?? 'N/A') . "\n\n";

echo "3.1. Debug extraction data in toRobawsApiPayload:\n";
if (!empty($mapped['extraction_data'])) {
    $extractionData = $mapped['extraction_data'];
    $rawData = $extractionData['raw_data'] ?? [];
    $contact = $extractionData['contact'] ?? [];
    
    echo "   raw_data company: " . ($rawData['company'] ?? 'N/A') . "\n";
    echo "   raw_data vat: " . ($rawData['vat'] ?? 'N/A') . "\n";
    echo "   raw_data website: " . ($rawData['website'] ?? 'N/A') . "\n";
    echo "   contact company: " . ($contact['company'] ?? 'N/A') . "\n";
} else {
    echo "   No extraction_data found in mapped array!\n";
}
echo "\n";

if (isset($payload['extraFields'])) {
    echo "4. Client extraFields:\n";
    foreach ($payload['extraFields'] as $key => $field) {
        if (in_array($key, ['client', 'clientAddress', 'clientStreet', 'clientZipcode', 'clientCity', 'clientCountry', 'clientTel', 'clientGsm', 'clientEmail', 'clientVat'])) {
            $value = $field['stringValue'] ?? $field['dateValue'] ?? 'null';
            echo "   $key: $value\n";
        }
    }
    echo "\n";
    
    echo "5. Contact extraFields:\n";
    foreach ($payload['extraFields'] as $key => $field) {
        if (strpos($key, 'CONTACT') === 0) {
            $value = $field['stringValue'] ?? $field['dateValue'] ?? 'null';
            echo "   $key: $value\n";
        }
    }
}

echo "\n=== END DEBUG ===\n";

<?php

require 'vendor/autoload.php';

use App\Models\Intake;
use App\Models\Extraction;
use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Export\Clients\RobawsApiClient;

// Test data based on the real Armos BV email
$testData = [
    'from' => 'Antwerpen',
    'to' => 'MOMBASA',
    'cargo_type' => 'Heftruck',
    'cargo_details' => 'Jungheftruck TFG435s - L390 cm, B230 cm, H310cm, 3500KG'
];

echo "=== ARMOS BV CONTACT PHONE TEST ===\n\n";

// Create test intake with the real Armos BV data structure
$intake = new Intake();
$intake->id = 999999; // Test ID
$intake->raw_data = [
    'subject' => 'Ro-Ro transport van Antwerpen naar MOMBASA',
    'content' => 'Goede middag\n\nKunnen jullie me aanbieden voor Ro-Ro transport van een heftruck van Antwerpen naar MOMBASA\n\nDetails heftruck:\n\nJungheftruck TFG435s\nL390 cm\nB230 cm\nH310cm\n3500KG\n\nHoor graag\n\nMvgr\nNancy',
    'contact' => [
        'name' => 'Nancy Deckers',
        'email' => 'nancy@armos.be',
        'phone' => '+32 (0)3 435 86 57',
        'mobile' => '+32 (0)476 72 02 16'
    ],
    'company' => 'Armos BV',
    'vat_number' => '0437 311 533',
    'website' => 'www.armos.be',
    'address' => 'Kapelsesteenweg 611, B-2180 Antwerp (Ekeren), Belgium'
];

// Test RobawsMapper using the public method
$mapper = new RobawsMapper();

echo "1. Testing mapIntakeToRobaws() with Armos BV data:\n";
$mappedData = $mapper->mapIntakeToRobaws($intake, $testData);

echo "   Raw mapped data structure:\n";
print_r($mappedData);

echo "\n2. Testing toRobawsApiPayload():\n";
$payload = $mapper->toRobawsApiPayload($mappedData);

echo "   Customer data:\n";
if (isset($payload['customer'])) {
    echo "   - Name: " . ($payload['customer']['name'] ?? 'NOT SET') . "\n";
    echo "   - VAT: " . ($payload['customer']['vat_number'] ?? 'NOT SET') . "\n";
    echo "   - Website: " . ($payload['customer']['website'] ?? 'NOT SET') . "\n";
    echo "   - Address: " . ($payload['customer']['address'] ?? 'NOT SET') . "\n";
}

echo "\n   Contact person data:\n";
if (isset($payload['contact'])) {
    echo "   - Name: " . ($payload['contact']['name'] ?? 'NOT SET') . "\n";
    echo "   - Email: " . ($payload['contact']['email'] ?? 'NOT SET') . "\n";
    echo "   - Phone: " . ($payload['contact']['phone'] ?? 'NOT SET') . "\n";
    echo "   - Mobile: " . ($payload['contact']['mobile'] ?? 'NOT SET') . "\n";
}

echo "\n   Template extraFields:\n";
if (isset($payload['extraFields'])) {
    echo "   - client: " . ($payload['extraFields']['client'] ?? 'NOT SET') . "\n";
    echo "   - clientVat: " . ($payload['extraFields']['clientVat'] ?? 'NOT SET') . "\n";
    echo "   - clientAddress: " . ($payload['extraFields']['clientAddress'] ?? 'NOT SET') . "\n";
    echo "   - clientWebsite: " . ($payload['extraFields']['clientWebsite'] ?? 'NOT SET') . "\n";
}

echo "\n=== TEST COMPLETE ===\n";

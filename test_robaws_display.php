<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Intake;
use App\Services\Export\Mappers\RobawsMapper;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "\n=== Testing RobawsMapper Display Formatting ===\n";

// Simulated extraction data from Oliver's enhanced German email
$testExtractionData = [
    'vehicle' => [
        'brand' => 'Suzuki',
        'model' => 'Samurai', 
        'condition' => 'used',
        'full_description' => '1 x used Suzuki Samurai connected to 1 x used RS-Camp caravan',
        'additional_info' => 'RS-Camp caravan',
        'length' => 5.30,
        'width' => 1.64,
        'height' => 1.71,
        'weight_kg' => 1500,
        'dimensions' => [
            'length_m' => 5.30,
            'width_m' => 1.64,
            'height_m' => 1.71,
        ],
        'type' => 'Vehicle with trailer',
        'quantity' => 1
    ],
    'vehicles' => [
        [
            'brand' => 'Suzuki',
            'model' => 'Samurai',
            'condition' => 'used',
            'full_description' => '1 x used Suzuki Samurai connected to 1 x used RS-Camp caravan',
            'additional_info' => 'RS-Camp caravan',
            'length' => 5.30,
            'width' => 1.64,
            'height' => 1.71,
            'weight_kg' => 1500,
            'dimensions' => [
                'length_m' => 5.30,
                'width_m' => 1.64,
                'height_m' => 1.71,
            ],
            'type' => 'Vehicle with trailer',
            'quantity' => 1
        ]
    ],
    'origin' => 'Germany',
    'destination' => 'Belgium',
    'origin_port' => 'Germany',
    'destination_port' => 'Belgium',
    'origin_location' => 'Deutschland',
    'destination_location' => 'Belgium',
    'raw_email_content' => 'Test email with German content - pickup from Germany for door to door delivery',
    'raw_text' => 'pickup from Germany for door to door delivery to Belgium',
    'raw_data' => [
        'cargo_description' => '1 x used Suzuki Samurai connected to 1 x used RS-Camp caravan'
    ],
    'shipping' => [
        'route' => [
            'origin' => ['location' => 'Germany'],
            'destination' => ['location' => 'Belgium']
        ]
    ],
    'shipment' => [
        'origin' => 'Germany',
        'destination' => 'Belgium'
    ]
];

// Create a mock intake
$intake = new Intake();
$intake->id = 12345;
$intake->email_subject = 'Transport Oliver Suzuki Samurai';
$intake->customer_email = 'oliver@example.com';

echo "\n1. Testing POL Mapping (Germany → Antwerp):\n";
$mapper = new RobawsMapper();
$robawsData = $mapper->mapIntakeToRobaws($intake, $testExtractionData);

echo "POL: " . ($robawsData['routing']['pol'] ?? 'Not set') . "\n";
echo "POD: " . ($robawsData['routing']['pod'] ?? 'Not set') . "\n";

echo "\n2. Testing Customer Reference Generation:\n";
echo "Concerning: " . ($robawsData['quotation_info']['concerning'] ?? 'Not set') . "\n";

echo "\n3. Testing Cargo Description:\n";
echo "Cargo: " . ($robawsData['cargo_details']['cargo'] ?? 'Not set') . "\n";

echo "\n4. Testing Dimensions Display (DIM_BEF_DELIVERY):\n";
echo "Dimensions Text: " . ($robawsData['cargo_details']['dimensions_text'] ?? 'Not set') . "\n";

echo "\n5. Testing Complete Robaws Export Structure:\n";
$exportArray = $mapper->toRobawsApiPayload($robawsData);

// Show key fields that were being displayed incorrectly
echo "\nKey Export Fields:\n";
echo "- POL: " . ($exportArray['POL'] ?? 'Not set') . "\n";
echo "- POD: " . ($exportArray['POD'] ?? 'Not set') . "\n";
echo "- CONCERNING: " . ($exportArray['CONCERNING'] ?? 'Not set') . "\n";
echo "- CARGO: " . ($exportArray['CARGO'] ?? 'Not set') . "\n";
echo "- DIM_BEF_DELIVERY: " . ($exportArray['DIM_BEF_DELIVERY'] ?? 'Not set') . "\n";

echo "\n6. Debug Full Structure:\n";
echo "Routing data: " . json_encode($robawsData['routing'] ?? [], JSON_PRETTY_PRINT) . "\n";
echo "Cargo details data: " . json_encode($robawsData['cargo_details'] ?? [], JSON_PRETTY_PRINT) . "\n";
echo "Quotation info data: " . json_encode($robawsData['quotation_info'] ?? [], JSON_PRETTY_PRINT) . "\n";

echo "\n=== Display Formatting Test Complete ===\n";

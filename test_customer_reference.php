<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Export\Mappers\RobawsMapper;
use App\Models\Intake;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$mapper = new RobawsMapper();
$intake = Intake::factory()->make();

echo "=== Testing CONCERNING content moved to Customer Reference ===\n\n";

// Test with BMW Serie 7 Brussels to Jeddah example
$extractionData = [
    'origin' => 'Bruxelles, Belgium',
    'destination' => 'Jeddah, Saudi Arabia',
    'document_data' => [
        'vehicle' => [
            'brand' => 'BMW',
            'model' => 'Série 7',
            'year' => '2021'
        ],
        'shipping' => [
            'transport_type' => 'RORO'
        ]
    ]
];

$mapped = $mapper->mapIntakeToRobaws($intake, $extractionData);
$mapped['customer_id'] = 999;
$payload = $mapper->toRobawsApiPayload($mapped);

echo "Quotation Info:\n";
echo "Customer Reference: '" . ($mapped['quotation_info']['customer_reference'] ?? 'NOT FOUND') . "'\n";
echo "Concerning: '" . ($mapped['quotation_info']['concerning'] ?? 'NOT FOUND') . "'\n\n";

echo "Expected Result:\n";
echo "- Customer Reference should contain: 'EXP - RORO - BRUSSEL - ANR - JEDDAH - 1 x BMW Série 7'\n";
echo "- Concerning should be empty\n\n";

echo "=== Test completed ===\n";

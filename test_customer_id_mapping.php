<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Export\Mappers\RobawsMapper;
use App\Models\Intake;

$mapper = new RobawsMapper();

// Create test intake with customer data
$intake = new Intake();
$intake->customer_name = 'Patrick Van Den Driessche';
$intake->customer_email = 'patrick@belgaco.be';

$extractionData = [
    'document_data' => [
        'contact' => [
            'name' => 'Patrick Van Den Driessche',
            'email' => 'patrick@belgaco.be',
            'company' => 'Belgaco'
        ],
        'vehicle' => [
            'brand' => 'BMW',
            'model' => 'Série 7'
        ]
    ]
];

$mapped = $mapper->mapIntakeToRobaws($intake, $extractionData);

echo "=== BEFORE adding customer_id ===" . PHP_EOL;
echo "customer_id in mapped: " . json_encode($mapped['customer_id'] ?? 'NOT FOUND') . PHP_EOL;

// Simulate what service should do - inject customer_id
$mapped['customer_id'] = 4321; // Test ID

echo "=== AFTER adding customer_id ===" . PHP_EOL;
echo "customer_id in mapped: " . json_encode($mapped['customer_id']) . PHP_EOL;

$payload = $mapper->toRobawsApiPayload($mapped);

echo "=== FINAL PAYLOAD ===" . PHP_EOL;
echo "Top-level customerId: " . json_encode($payload['customerId'] ?? 'NOT FOUND') . PHP_EOL;
echo "Top-level contactEmail: " . json_encode($payload['contactEmail'] ?? 'NOT FOUND') . PHP_EOL;
echo "Customer Reference: " . json_encode($payload['quotationInfo']['customer_reference'] ?? 'NOT FOUND') . PHP_EOL;

echo PHP_EOL . "=== FULL PAYLOAD STRUCTURE ===" . PHP_EOL;
echo json_encode($payload, JSON_PRETTY_PRINT);

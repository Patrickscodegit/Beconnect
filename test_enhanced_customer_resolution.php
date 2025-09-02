<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Export\Clients\RobawsApiClient;

echo "=== Testing Enhanced Customer Resolution ===" . PHP_EOL;

$apiClient = new RobawsApiClient();

// Test cases
$testCases = [
    ['name' => 'Patrick Van Den Driessche', 'email' => 'patrick@belgaco.be'],
    ['name' => 'Test Customer', 'email' => 'test@example.com'],
    ['name' => 'Belgaco', 'email' => null],
    ['name' => null, 'email' => 'sales@truck-time.com'],
];

foreach ($testCases as $i => $test) {
    echo PHP_EOL . "--- Test Case " . ($i + 1) . " ---" . PHP_EOL;
    echo "Name: " . ($test['name'] ?? 'null') . PHP_EOL;
    echo "Email: " . ($test['email'] ?? 'null') . PHP_EOL;
    
    try {
        $customerId = $apiClient->findCustomerId($test['name'], $test['email']);
        echo "Result: " . ($customerId ? "Customer ID {$customerId}" : "No customer found") . PHP_EOL;
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL . "=== Testing Full Pipeline ===" . PHP_EOL;

use App\Services\Export\Mappers\RobawsMapper;
use App\Models\Intake;

$mapper = new RobawsMapper();
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

// Simulate customer resolution
$customerId = $apiClient->findCustomerId('Patrick Van Den Driessche', 'patrick@belgaco.be');

$mapped = $mapper->mapIntakeToRobaws($intake, $extractionData);
$mapped['customer_id'] = $customerId;
if ($intake->customer_email) {
    $mapped['quotation_info']['contact_email'] = $intake->customer_email;
}

$payload = $mapper->toRobawsApiPayload($mapped);

echo "Final customerId in payload: " . json_encode($payload['customerId'] ?? 'NOT FOUND') . PHP_EOL;
echo "Final contactEmail in payload: " . json_encode($payload['contactEmail'] ?? 'NOT FOUND') . PHP_EOL;
echo "Final clientReference in payload: " . json_encode($payload['clientReference'] ?? 'NOT FOUND') . PHP_EOL;

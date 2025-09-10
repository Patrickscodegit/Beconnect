<?php

require_once __DIR__ . '/bootstrap/app.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$intake = App\Models\Intake::find(11);
if (!$intake) {
    echo "Intake not found\n";
    exit(1);
}

echo "=== INTAKE ===\n";
echo "Customer: " . $intake->customer_name . "\n";

$mapper = app(\App\Services\Export\Mappers\RobawsMapper::class);
$mapped = $mapper->mapIntakeToRobaws($intake);

echo "\n=== CUSTOMER DATA ===\n";
print_r($mapped['customer_data']);

$payload = $mapper->toRobawsApiPayload($mapped);

echo "\n=== CLIENT FIELDS ===\n";
foreach($payload['extraFields'] ?? [] as $field) {
    if (strpos($field['code'], 'CLIENT') === 0) {
        echo $field['code'] . ': ' . ($field['stringValue'] ?? 'null') . "\n";
    }
}

echo "\n=== STREET ANALYSIS ===\n";
echo "Customer data street: " . ($mapped['customer_data']['street'] ?? 'null') . "\n";
echo "Customer data address: " . ($mapped['customer_data']['address'] ?? 'null') . "\n";

// Check what's in the client update data
$clientData = [
    'name' => $mapped['customer_data']['company_name'] ?? $mapped['customer_data']['customer_name'],
    'email' => $mapped['customer_data']['email'],
    'tel' => $mapped['customer_data']['phone'],
    'gsm' => $mapped['customer_data']['mobile'],
    'vatNumber' => $mapped['customer_data']['vat_number'],
    'website' => $mapped['customer_data']['website'],
    'language' => 'nl',
    'currency' => 'EUR',
    'clientType' => 'company',
    'street' => $mapped['customer_data']['street'],
    'city' => $mapped['customer_data']['city'],
    'postal_code' => $mapped['customer_data']['postal_code'],
    'country' => $mapped['customer_data']['country'],
];

echo "\n=== CLIENT UPDATE DATA ===\n";
foreach($clientData as $key => $value) {
    echo "$key: " . ($value ?? 'null') . "\n";
}

<?php

require_once __DIR__ . '/bootstrap/app.php';

$app = Illuminate\Foundation\Application::getInstance();
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$intake = App\Models\Intake::find(11);
if (!$intake) {
    echo "Intake 11 not found\n";
    exit(1);
}

echo "=== INTAKE DATA ===\n";
echo "Customer: " . $intake->customer_name . "\n";
echo "Email: " . $intake->contact_email . "\n";

$extractionData = $intake->extraction_data;

$mapper = app(\App\Services\Export\Mappers\RobawsMapper::class);
$mapped = $mapper->mapIntakeToRobaws($intake, $extractionData);

echo "\n=== CUSTOMER DATA ===\n";
print_r($mapped['customer_data']);

$payload = $mapper->toRobawsApiPayload($mapped);
$extraFields = $payload['extraFields'] ?? [];
echo "\n=== CLIENT FIELDS ===\n";
foreach($extraFields as $field) {
    if (strpos($field['code'], 'CLIENT') === 0) {
        echo $field['code'] . ': ' . ($field['stringValue'] ?? $field['dateValue'] ?? 'null') . "\n";
    }
}

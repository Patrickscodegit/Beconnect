<?php

require_once __DIR__ . '/bootstrap/app.php';

$intake = new App\Models\Intake();
$intake->id = 11;
$intake->customer_name = 'Nancy Deckers';  // This should be overridden by raw_data company
$intake->contact_email = null;

// Set the extraction data
$intake->extraction_data = [
    "contact" => [
        "name" => "Nancy Deckers",
        "email" => "nancy@armos.be",
        "phone" => "+32 (0)3 435 86 57",
        "mobile" => "+32 (0)476 72 02 16",
        "company" => "Armos BV"
    ],
    "raw_data" => [
        "company" => "Armos BV",
        "contact_name" => "Nancy Deckers",
        "email" => "nancy@armos.be",
        "phone" => "+32 (0)3 435 86 57",
        "mobile" => "+32 (0)476 72 02 16",
        "vat" => "0437 311 533",
        "website" => "www.armos.be",
        "address" => "Kapelsesteenweg 611, B-2180 Antwerp (Ekeren), Belgium",
        "street" => "Kapelsesteenweg 611",
        "city" => "Antwerp (Ekeren)",
        "zip" => "B-2180",
        "country" => "Belgium"
    ]
];

$mapper = new App\Services\Export\Mappers\RobawsMapper();

// Test mapIntakeToRobaws
$mapped = $mapper->mapIntakeToRobaws($intake);

echo "=== QUOTATION INFO ===\n";
echo "Customer: " . ($mapped['quotation_info']['customer'] ?? 'N/A') . "\n";
echo "Contact: " . ($mapped['quotation_info']['contact'] ?? 'N/A') . "\n";
echo "Email: " . ($mapped['quotation_info']['contact_email'] ?? 'N/A') . "\n";

// Test toRobawsApiPayload
$payload = $mapper->toRobawsApiPayload($mapped);

echo "\n=== ROBAWS API PAYLOAD ===\n";
echo "Title: " . ($payload['title'] ?? 'N/A') . "\n";
echo "Contact Email: " . ($payload['contactEmail'] ?? 'N/A') . "\n";

echo "\n=== EXTRA FIELDS (CLIENT) ===\n";
foreach ($payload['extraFields'] ?? [] as $field) {
    if (strpos($field['code'], 'CLIENT') === 0) {
        echo $field['code'] . ': ' . ($field['stringValue'] ?? 'null') . "\n";
    }
}

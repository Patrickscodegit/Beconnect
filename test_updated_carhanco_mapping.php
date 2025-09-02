<?php

// Test updated Carhanco mapping
$intake = App\Models\Intake::find(2);
echo "=== TESTING UPDATED CARHANCO MAPPING ===\n\n";

$mapper = new App\Services\Export\Mappers\RobawsMapper();
$mapped = $mapper->mapIntakeToRobaws($intake);

echo "Updated mapping results:\n";
echo "Cargo: '" . ($mapped['cargo_details']['cargo'] ?? 'empty') . "'\n";
echo "Dimensions: '" . ($mapped['cargo_details']['dimensions_text'] ?? 'empty') . "'\n";
echo "POR: '" . ($mapped['routing']['por'] ?? 'empty') . "'\n";
echo "POL: '" . ($mapped['routing']['pol'] ?? 'empty') . "'\n";
echo "POD: '" . ($mapped['routing']['pod'] ?? 'empty') . "'\n";

echo "\nFull mapping structure:\n";
echo "Quotation info: ";
print_r($mapped['quotation_info']);
echo "\nRouting: ";
print_r($mapped['routing']);
echo "\nCargo details: ";
print_r($mapped['cargo_details']);

// Test API payload
echo "\n=== API PAYLOAD TEST ===\n";
$mapped['client_id'] = 4007;
$mapped['contact_email'] = 'sales@truck-time.com';
$payload = $mapper->toRobawsApiPayload($mapped);

echo "Payload top-level fields:\n";
foreach (['title', 'clientReference', 'contactEmail', 'clientId'] as $field) {
    echo "  $field: '" . ($payload[$field] ?? 'empty') . "'\n";
}

if (isset($payload['extraFields'])) {
    echo "\nKey extraFields:\n";
    foreach (['CARGO', 'DIM_BEF_DELIVERY', 'POR', 'POL', 'POD'] as $field) {
        if (isset($payload['extraFields'][$field])) {
            echo "  $field: '" . ($payload['extraFields'][$field]['stringValue'] ?? 'empty') . "'\n";
        }
    }
}

<?php

// Debug the complete export payload for intake 4
$intake = App\Models\Intake::find(4);
$mapper = new App\Services\Export\Mappers\RobawsMapper();

echo "=== INTAKE 4 EXPORT DEBUG ===\n\n";

// Get extraction data
$extractionData = $intake->extraction_data;
echo "1. Extraction data available:\n";
echo "   - Raw data sections: " . implode(', ', array_keys($extractionData['raw_data'] ?? [])) . "\n";
echo "   - Document data sections: " . implode(', ', array_keys($extractionData['document_data'] ?? [])) . "\n\n";

// Check specific routing data
if (isset($extractionData['raw_data']['shipping']['route'])) {
    echo "2. Raw shipping route data:\n";
    echo json_encode($extractionData['raw_data']['shipping']['route'], JSON_PRETTY_PRINT) . "\n\n";
}

if (isset($extractionData['raw_data']['shipment'])) {
    echo "3. Raw shipment data:\n";
    echo json_encode($extractionData['raw_data']['shipment'], JSON_PRETTY_PRINT) . "\n\n";
}

// Test mapper
echo "4. Mapper output:\n";
$mapped = $mapper->mapIntakeToRobaws($intake, $extractionData);
echo "   Routing section: " . json_encode($mapped['routing'], JSON_PRETTY_PRINT) . "\n\n";

// Test API payload conversion
echo "5. Final API payload structure:\n";
$payload = $mapper->toRobawsApiPayload($mapped);
echo "   Top-level keys: " . implode(', ', array_keys($payload)) . "\n";

if (isset($payload['extraFields'])) {
    echo "   ExtraFields routing keys: ";
    $routingKeys = [];
    foreach (['POR', 'POL', 'POD', 'FDEST'] as $key) {
        if (isset($payload['extraFields'][$key])) {
            $value = $payload['extraFields'][$key]['stringValue'] ?? 'null';
            $routingKeys[] = "$key='$value'";
        }
    }
    echo implode(', ', $routingKeys) . "\n\n";
    
    echo "   Complete extraFields routing section:\n";
    $routingFields = [];
    foreach (['POR', 'POL', 'POD', 'FDEST'] as $key) {
        if (isset($payload['extraFields'][$key])) {
            $routingFields[$key] = $payload['extraFields'][$key];
        }
    }
    echo json_encode($routingFields, JSON_PRETTY_PRINT) . "\n";
}

echo "\n=== END DEBUG ===\n";

<?php

// Analyze Carhanco mapping issue
$intake = App\Models\Intake::find(2);
echo "=== CARHANCO MAPPING ANALYSIS ===\n\n";

$extractionData = $intake->extraction_data;

// Check raw_data structure
if (isset($extractionData['raw_data'])) {
    echo "Raw data keys: " . implode(', ', array_keys($extractionData['raw_data'])) . "\n\n";
    
    // Check for vehicle data
    echo "Vehicle info:\n";
    echo "  Make: " . ($extractionData['raw_data']['vehicle_make'] ?? 'none') . "\n";
    echo "  Model: " . ($extractionData['raw_data']['vehicle_model'] ?? 'none') . "\n";
    echo "  Year: " . ($extractionData['raw_data']['vehicle_year'] ?? 'none') . "\n";
    echo "  Condition: " . ($extractionData['raw_data']['vehicle_condition'] ?? 'none') . "\n";
    echo "  Dimensions: " . ($extractionData['raw_data']['dimensions'] ?? 'none') . "\n";
    echo "  Weight: " . ($extractionData['raw_data']['weight'] ?? 'none') . "\n";
    
    // Check for shipping data
    echo "\nShipping info:\n";
    echo "  Origin: " . ($extractionData['raw_data']['origin'] ?? 'none') . "\n";
    echo "  Destination: " . ($extractionData['raw_data']['destination'] ?? 'none') . "\n";
    echo "  Type: " . ($extractionData['raw_data']['shipment_type'] ?? 'none') . "\n";
    echo "  Cargo description: " . ($extractionData['raw_data']['cargo_description'] ?? 'none') . "\n";
}

// Test current mapping
$mapper = new App\Services\Export\Mappers\RobawsMapper();
$mapped = $mapper->mapIntakeToRobaws($intake, $extractionData);

echo "\n=== CURRENT MAPPING RESULTS ===\n";
echo "Cargo: '" . ($mapped['cargo_details']['cargo'] ?? 'empty') . "'\n";
echo "Dimensions: '" . ($mapped['cargo_details']['dimensions_text'] ?? 'empty') . "'\n";
echo "POR: '" . ($mapped['routing']['por'] ?? 'empty') . "'\n";
echo "POL: '" . ($mapped['routing']['pol'] ?? 'empty') . "'\n";
echo "POD: '" . ($mapped['routing']['pod'] ?? 'empty') . "'\n";

echo "\n=== DATA STRUCTURE FOR MAPPER ===\n";
// Check what the mapper is actually getting
$rawData = $extractionData['raw_data'] ?? [];
$vehicle = $rawData['vehicle'] ?? [];
$shipping = $rawData['shipping'] ?? [];
$shipment = $rawData['shipment'] ?? [];

echo "Vehicle array from raw_data: ";
var_export($vehicle);
echo "\nShipping array from raw_data: ";
var_export($shipping);
echo "\nShipment array from raw_data: ";
var_export($shipment);

echo "\n\n=== EXPECTED STRUCTURE FOR MAPPING ===\n";
echo "The mapper expects data in nested arrays but raw_data has flat fields\n";
echo "Need to restructure the data access in the mapper\n";

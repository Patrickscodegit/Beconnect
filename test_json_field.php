<?php

require_once 'vendor/autoload.php';

use App\Services\Extraction\Strategies\ImageExtractionStrategy;
use App\Models\Document;

// Create a mock document for testing
$mockDocument = new Document([
    'id' => 999,
    'filename' => 'test_vehicle.jpg',
    'mime_type' => 'image/jpeg'
]);

// Create mock extracted data similar to what VehicleDataEnhancer would produce
$mockExtractedData = [
    'vehicle' => [
        'make' => 'Alfa Romeo',
        'model' => 'Giulietta',
        'year' => 1960,
        'condition' => 'Used',
        'engine_cc' => 1290,
        'fuel_type' => 'Petrol',
        'weight' => ['value' => 1050, 'unit' => 'kg'],
        'dimensions' => [
            'length' => 4.35,
            'width' => 1.80,
            'height' => 1.46,
            'unit' => 'm'
        ],
        'cargo_volume_m3' => 2.5,
        'calculated_volume_m3' => 11.4,
        'shipping_weight_class' => 'Light',
        'typical_container' => '20ft',
        'recommended_container' => '20ft Standard'
    ],
    'shipment' => [
        'origin' => 'Brussels',
        'destination' => 'Jeddah',
        'type' => 'RORO',
        'service' => 'Standard'
    ],
    'contact' => [
        'name' => 'John Doe',
        'company' => 'Test Company',
        'email' => 'john@test.com'
    ],
    'pricing' => [
        'amount' => 2500,
        'currency' => 'EUR'
    ],
    'data_sources' => [
        'document_extracted' => ['make', 'model', 'year'],
        'database_enhanced' => ['engine_cc', 'fuel_type'],
        'ai_enhanced' => ['weight', 'dimensions', 'cargo_volume_m3'],
        'calculated' => ['calculated_volume_m3', 'shipping_weight_class']
    ],
    'enhancement_metadata' => [
        'confidence' => 0.85,
        'enhanced_at' => '2025-08-29T10:30:00Z',
        'enhancement_time_ms' => 1250
    ]
];

// Create an instance of ImageExtractionStrategy
$strategy = new ImageExtractionStrategy();

// Use reflection to access the protected transformForRobaws method
$reflection = new ReflectionClass($strategy);
$method = $reflection->getMethod('transformForRobaws');
$method->setAccessible(true);

// Call the method with our mock data
$transformedData = $method->invoke($strategy, $mockExtractedData);

echo "=== ROBAWS TRANSFORMATION TEST ===\n";
echo "Total fields: " . count($transformedData) . "\n";
echo "Has JSON field: " . (isset($transformedData['JSON']) ? 'YES' : 'NO') . "\n";

if (isset($transformedData['JSON'])) {
    echo "JSON field length: " . strlen($transformedData['JSON']) . " characters\n";
    echo "\nFirst 500 characters of JSON field:\n";
    echo substr($transformedData['JSON'], 0, 500) . "...\n";
}

echo "\nHas raw_json field: " . (isset($transformedData['raw_json']) ? 'YES' : 'NO') . "\n";
echo "Has extraction_json field: " . (isset($transformedData['extraction_json']) ? 'YES' : 'NO') . "\n";

echo "\nAll transformed fields:\n";
foreach ($transformedData as $key => $value) {
    if ($key === 'JSON' || $key === 'raw_json' || $key === 'extraction_json') {
        echo "- $key: [JSON data, " . strlen($value) . " chars]\n";
    } else {
        echo "- $key: " . (is_array($value) ? json_encode($value) : $value) . "\n";
    }
}

echo "\n=== JSON FIELD MAPPER TEST ===\n";

// Test the JsonFieldMapper
use App\Services\RobawsIntegration\JsonFieldMapper;

try {
    $mapper = new JsonFieldMapper();
    $mappedData = $mapper->mapFields($transformedData);
    
    echo "Mapped fields: " . count($mappedData) . "\n";
    echo "Has JSON in mapped data: " . (isset($mappedData['JSON']) ? 'YES' : 'NO') . "\n";
    
    if (isset($mappedData['JSON'])) {
        echo "Mapped JSON length: " . strlen($mappedData['JSON']) . " characters\n";
    }
    
    echo "\nSample mapped fields:\n";
    $sampleFields = array_slice($mappedData, 0, 10, true);
    foreach ($sampleFields as $key => $value) {
        if ($key === 'JSON') {
            echo "- $key: [JSON data, " . strlen($value) . " chars]\n";
        } else {
            echo "- $key: " . (is_array($value) ? json_encode($value) : $value) . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "JsonFieldMapper error: " . $e->getMessage() . "\n";
}

echo "\n=== TEST COMPLETED ===\n";

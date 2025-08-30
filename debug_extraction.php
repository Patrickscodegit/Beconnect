<?php

require_once __DIR__ . '/bootstrap/app.php';

echo "🔍 Debugging Vehicle Extraction\n";
echo "=" . str_repeat("=", 50) . "\n";

// Test with the actual Bentley Continental text
$testText = "BENTLEY CONTINENTAL
VIN: SCBFF63W2HC064730
Model: 2017
Color: BLACK";

echo "📄 Testing with text:\n$testText\n\n";

// Create the extraction services
$vehicleDb = app(\App\Services\VehicleDatabase\VehicleDatabaseService::class);
$patternExtractor = new \App\Services\Extraction\Strategies\PatternExtractor($vehicleDb);

// Run pattern extraction directly
echo "🎯 Pattern Extraction Results:\n";
$patternResult = $patternExtractor->extract($testText);

echo "Vehicle data found:\n";
print_r($patternResult['vehicle'] ?? []);

echo "\n🔍 Checking for brand/model fields:\n";
$vehicle = $patternResult['vehicle'] ?? [];
echo "Brand: " . ($vehicle['brand'] ?? 'NOT SET') . "\n";
echo "Model: " . ($vehicle['model'] ?? 'NOT SET') . "\n";
echo "Make: " . ($vehicle['make'] ?? 'NOT SET') . "\n";

echo "\n📊 Full extraction result:\n";
echo json_encode($patternResult, JSON_PRETTY_PRINT);

echo "\n✅ Debug complete\n";

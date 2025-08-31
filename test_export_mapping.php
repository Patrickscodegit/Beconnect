<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Intake;
use App\Models\Document;
use App\Services\Robaws\RobawsExportService;
use App\Services\RobawsIntegration\JsonFieldMapper;

echo "🔍 Testing RobawsExportService with JsonFieldMapper Integration\n\n";

try {
    // Find a recent intake with documents
    $intake = Intake::whereHas('documents')->latest()->first();
    
    if (!$intake) {
        echo "❌ No intake with documents found\n";
        exit(1);
    }
    
    echo "📋 Testing with Intake ID: {$intake->id}\n";
    
    // Get the extraction data
    $extraction = $intake->extraction;
    if (!$extraction) {
        echo "❌ No extraction found for intake {$intake->id}\n";
        exit(1);
    }
    
    $extractionData = $extraction->extracted_data ?? $extraction->raw_json ?? null;
    if (!$extractionData || !is_array($extractionData)) {
        echo "❌ No valid extraction data found\n";
        exit(1);
    }
    
    echo "✅ Found extraction data with " . count($extractionData) . " fields\n";
    echo "🔑 Top-level keys: " . implode(', ', array_slice(array_keys($extractionData), 0, 10)) . "\n\n";
    
    // Test JsonFieldMapper directly first
    echo "🎯 Testing JsonFieldMapper directly...\n";
    $fieldMapper = new JsonFieldMapper();
    $mappedData = $fieldMapper->mapFields($extractionData);
    
    echo "📊 JsonFieldMapper Results:\n";
    echo "  - Mapped fields: " . count($mappedData) . "\n";
    echo "  - Customer: " . ($mappedData['customer'] ?? 'NOT MAPPED') . "\n";
    echo "  - Origin (POR): " . ($mappedData['por'] ?? 'NOT MAPPED') . "\n";
    echo "  - Destination (POD): " . ($mappedData['pod'] ?? 'NOT MAPPED') . "\n";
    echo "  - Cargo: " . ($mappedData['cargo'] ?? 'NOT MAPPED') . "\n";
    echo "  - Vehicle Brand: " . ($mappedData['vehicle_brand'] ?? 'NOT MAPPED') . "\n";
    echo "  - Vehicle Model: " . ($mappedData['vehicle_model'] ?? 'NOT MAPPED') . "\n\n";
    
    // Test RobawsExportService mapping
    echo "🚀 Testing RobawsExportService mapping...\n";
    $exportService = app(RobawsExportService::class);
    
    // Use reflection to call the protected method
    $reflection = new ReflectionClass($exportService);
    $method = $reflection->getMethod('mapExtractionToRobaws');
    $method->setAccessible(true);
    
    $robawsPayload = $method->invoke($exportService, $extractionData);
    
    echo "📦 RobawsExportService Results:\n";
    echo "  - Payload fields: " . count($robawsPayload) . "\n";
    echo "  - Title: " . ($robawsPayload['title'] ?? 'NOT SET') . "\n";
    echo "  - Origin: " . ($robawsPayload['origin'] ?? 'NOT SET') . "\n";
    echo "  - Destination: " . ($robawsPayload['destination'] ?? 'NOT SET') . "\n";
    echo "  - Cargo Description: " . ($robawsPayload['cargo_description'] ?? 'NOT SET') . "\n";
    echo "  - Vehicle Count: " . ($robawsPayload['vehicle_count'] ?? 'NOT SET') . "\n";
    echo "  - Client ID: " . ($robawsPayload['clientId'] ?? 'NOT SET') . "\n";
    echo "  - Has Mapping Metadata: " . (isset($robawsPayload['extraction_metadata']['mapping_version']) ? 'YES' : 'NO') . "\n\n";
    
    // Show detailed mapping metadata if available
    if (isset($robawsPayload['extraction_metadata'])) {
        echo "🔍 Mapping Metadata:\n";
        foreach ($robawsPayload['extraction_metadata'] as $key => $value) {
            echo "  - {$key}: " . (is_array($value) ? json_encode($value) : $value) . "\n";
        }
        echo "\n";
    }
    
    // Compare what was extracted vs what was mapped
    echo "🔄 Field Mapping Comparison:\n";
    
    $comparisonFields = [
        'vehicle_brand' => ['paths' => ['vehicle.brand', 'document_data.vehicle.brand', 'vehicle_make'], 'mapped' => $mappedData['vehicle_brand'] ?? null],
        'origin' => ['paths' => ['origin', 'shipment.origin', 'document_data.shipment.origin'], 'mapped' => $mappedData['por'] ?? null],
        'destination' => ['paths' => ['destination', 'shipment.destination', 'document_data.shipment.destination'], 'mapped' => $mappedData['pod'] ?? null],
        'customer' => ['paths' => ['contact.name', 'document_data.contact.name', 'email_metadata.from'], 'mapped' => $mappedData['customer'] ?? null]
    ];
    
    foreach ($comparisonFields as $fieldName => $info) {
        echo "  📍 {$fieldName}:\n";
        echo "    Raw extraction paths checked:\n";
        foreach ($info['paths'] as $path) {
            $value = data_get($extractionData, $path);
            echo "      - {$path}: " . ($value ? $value : 'NOT FOUND') . "\n";
        }
        echo "    Mapped result: " . ($info['mapped'] ? $info['mapped'] : 'NOT MAPPED') . "\n\n";
    }
    
    echo "✅ Field mapping integration test completed!\n";
    
    // Check if there's a document_data structure
    if (isset($extractionData['document_data'])) {
        echo "🔍 Found document_data structure - checking nested paths:\n";
        $docData = $extractionData['document_data'];
        echo "  - document_data keys: " . implode(', ', array_keys($docData)) . "\n";
        
        if (isset($docData['vehicle'])) {
            echo "  - vehicle keys: " . implode(', ', array_keys($docData['vehicle'])) . "\n";
            echo "  - vehicle.brand: " . ($docData['vehicle']['brand'] ?? 'NOT FOUND') . "\n";
            echo "  - vehicle.model: " . ($docData['vehicle']['model'] ?? 'NOT FOUND') . "\n";
        }
        
        if (isset($docData['shipment'])) {
            echo "  - shipment keys: " . implode(', ', array_keys($docData['shipment'])) . "\n";
            echo "  - shipment.origin: " . ($docData['shipment']['origin'] ?? 'NOT FOUND') . "\n";
            echo "  - shipment.destination: " . ($docData['shipment']['destination'] ?? 'NOT FOUND') . "\n";
        }
    }

} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n🏁 Test completed.\n";

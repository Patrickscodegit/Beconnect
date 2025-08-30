<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔧 DEBUG PAYLOAD BUILDING PROCESS\n";
echo "==================================\n\n";

// Get the latest document with extraction data
$document = App\Models\Document::find(71);

if (!$document) {
    echo "❌ Document 71 not found\n";
    exit;
}

echo "📄 Document: {$document->filename}\n";
echo "Extraction data type: " . gettype($document->extraction_data) . "\n";

// Decode extraction data
$extractedData = $document->extraction_data;
if (is_string($extractedData)) {
    $extractedData = json_decode($extractedData, true);
}

if (!$extractedData) {
    echo "❌ No extraction data available\n";
    exit;
}

echo "✅ Extraction data loaded\n";
echo "Available keys: " . implode(', ', array_keys($extractedData)) . "\n\n";

// Test the buildOfferPayload method using reflection
try {
    $service = app(App\Services\RobawsIntegrationService::class);
    
    // Use reflection to access the private method
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('buildOfferPayload');
    $method->setAccessible(true);
    
    echo "🧪 Testing buildOfferPayload method...\n";
    
    $payload = $method->invoke($service, $extractedData, 4161, null);
    
    echo "✅ Payload built successfully\n";
    echo "Total fields: " . count($payload) . "\n\n";
    
    // Check specific fields
    $targetFields = ['JSON', 'Customer', 'Customer reference', 'POR', 'POL', 'POD', 'CARGO', 'Contact', 'DIM_BEF_DELIVERY'];
    
    echo "🎯 Target field values:\n";
    foreach ($targetFields as $field) {
        $value = $payload[$field] ?? null;
        if ($value) {
            $preview = strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value;
            echo "  ✅ {$field}: {$preview}\n";
        } else {
            echo "  ❌ {$field}: EMPTY\n";
        }
    }
    
    echo "\n📋 Full payload structure:\n";
    foreach ($payload as $key => $value) {
        if (is_string($value)) {
            $preview = strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
            echo "  {$key}: {$preview}\n";
        } else {
            echo "  {$key}: [" . gettype($value) . "]\n";
        }
    }
    
    // Check the extraction data structure that's being used
    echo "\n🔍 Checking extraction data structure for field mapping:\n";
    
    if (isset($extractedData['vehicle'])) {
        echo "Vehicle data:\n";
        $vehicle = $extractedData['vehicle'];
        echo "  brand: " . ($vehicle['brand'] ?? 'missing') . "\n";
        echo "  model: " . ($vehicle['model'] ?? 'missing') . "\n";
        echo "  year: " . ($vehicle['year'] ?? 'missing') . "\n";
        echo "  condition: " . ($vehicle['condition'] ?? 'missing') . "\n";
        echo "  dimensions_m: " . (isset($vehicle['dimensions_m']) ? 'present' : 'missing') . "\n";
    }
    
    if (isset($extractedData['shipment'])) {
        echo "Shipment data:\n";
        $shipment = $extractedData['shipment'];
        echo "  origin: " . ($shipment['origin'] ?? 'missing') . "\n";
        echo "  destination: " . ($shipment['destination'] ?? 'missing') . "\n";
    }
    
    if (isset($extractedData['contact'])) {
        echo "Contact data:\n";
        $contact = $extractedData['contact'];
        echo "  name: " . ($contact['name'] ?? 'missing') . "\n";
        echo "  email: " . ($contact['email'] ?? 'missing') . "\n";
        echo "  phone: " . ($contact['phone'] ?? 'missing') . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error testing payload building: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "🚨 EMERGENCY DIAGNOSTIC - CHECKING CURRENT PAYLOAD\n";
echo "==================================================\n\n";

use App\Models\Document;
use App\Services\RobawsIntegrationService;

// Get a document with extraction
$document = Document::whereNotNull('extraction_data')
    ->orderBy('created_at', 'desc')
    ->first();

if (!$document) {
    echo "No document found.\n";
    exit;
}

$extractedData = is_string($document->extraction_data) 
    ? json_decode($document->extraction_data, true) 
    : $document->extraction_data;

echo "Document ID: {$document->id}\n";
echo "Extraction data type: " . gettype($extractedData) . "\n";
echo "Extraction data keys: " . (is_array($extractedData) ? implode(', ', array_keys($extractedData)) : 'N/A') . "\n\n";

// Create service and test payload
$service = app(RobawsIntegrationService::class);
$reflection = new ReflectionClass($service);
$method = $reflection->getMethod('buildOfferPayload');
$method->setAccessible(true);

try {
    echo "=== TESTING CURRENT buildOfferPayload ===\n";
    
    // Test with the parameters as they were working before
    $payload = $method->invoke($service, $extractedData, 1, null);
    
    echo "✅ Method executed successfully\n";
    echo "Payload type: " . gettype($payload) . "\n";
    echo "Payload keys: " . implode(', ', array_keys($payload)) . "\n\n";
    
    echo "=== JSON FIELD CHECK ===\n";
    if (isset($payload['JSON'])) {
        echo "✅ JSON field exists\n";
        echo "JSON length: " . strlen($payload['JSON']) . "\n";
        echo "JSON is valid: " . (json_decode($payload['JSON']) !== null ? 'YES' : 'NO') . "\n";
    } else {
        echo "❌ JSON field is MISSING!\n";
    }
    
    echo "\n=== ALL FIELDS ===\n";
    foreach ($payload as $key => $value) {
        $display = is_string($value) ? substr($value, 0, 50) . '...' : json_encode($value);
        echo "{$key}: {$display}\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error in buildOfferPayload: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== QUICK FIX TEST ===\n";
// Test the simplest possible payload that should work
$simplePayload = [
    'clientId' => 1,
    'name' => 'Test Offer',
    'status' => 'DRAFT',
    'JSON' => json_encode($extractedData),
];

echo "Simple payload keys: " . implode(', ', array_keys($simplePayload)) . "\n";
echo "Simple JSON length: " . strlen($simplePayload['JSON']) . "\n";

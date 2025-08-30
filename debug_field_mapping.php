<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;
use App\Services\RobawsIntegrationService;

echo "🔍 DEBUGGING FIELD MAPPING PAYLOAD\n";
echo "==================================\n";

// Get document 3 that we've been testing with
$document = Document::find(3);
if (!$document) {
    echo "❌ Document 3 not found\n";
    exit(1);
}

$extraction = $document->extractions()->latest()->first();
if (!$extraction) {
    echo "❌ No extraction found\n";
    exit(1);
}

echo "📄 Document: {$document->filename}\n";
echo "📊 Extraction Status: {$extraction->status}\n";

// Get the extracted data
$extractedData = is_string($extraction->extracted_data) 
    ? json_decode($extraction->extracted_data, true) 
    : $extraction->extracted_data;

echo "\n🔍 Raw Extraction Data Structure:\n";
echo json_encode($extractedData, JSON_PRETTY_PRINT) . "\n";

// Test the RobawsIntegrationService directly
$robawsService = app(RobawsIntegrationService::class);

echo "\n🚀 Testing buildOfferPayload method...\n";

try {
    // Use reflection to call the private method
    $reflection = new ReflectionClass($robawsService);
    $method = $reflection->getMethod('buildOfferPayload');
    $method->setAccessible(true);
    
    $payload = $method->invoke($robawsService, $extractedData, 1, $extraction);
    
    echo "✅ Payload Generated:\n";
    echo json_encode($payload, JSON_PRETTY_PRINT) . "\n";
    
    echo "\n🔍 Checking Individual Fields:\n";
    echo "Customer: " . ($payload['Customer'] ?? 'NOT SET') . "\n";
    echo "POR: " . ($payload['POR'] ?? 'NOT SET') . "\n";
    echo "POL: " . ($payload['POL'] ?? 'NOT SET') . "\n";
    echo "POD: " . ($payload['POD'] ?? 'NOT SET') . "\n";
    echo "CARGO: " . (isset($payload['CARGO']) ? (strlen($payload['CARGO']) > 50 ? substr($payload['CARGO'], 0, 50) . '...' : $payload['CARGO']) : 'NOT SET') . "\n";
    echo "JSON Field: " . (isset($payload['JSON']) ? 'SET' : 'NOT SET') . "\n";
    
} catch (Exception $e) {
    echo "❌ Error testing buildOfferPayload: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n🎯 Debug Complete!\n";

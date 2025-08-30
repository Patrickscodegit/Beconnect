<?php

use App\Models\Document;
use App\Services\RobawsIntegrationService;

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Get a recent document with extraction data
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
echo "Filename: {$document->filename}\n\n";

// Get the service and test the payload building
$service = app(RobawsIntegrationService::class);
$reflection = new ReflectionClass($service);
$method = $reflection->getMethod('buildOfferPayload');
$method->setAccessible(true);

try {
    $payload = $method->invoke($service, $extractedData, 1, null); // clientId = 1
    
    echo "=== PAYLOAD STRUCTURE ===\n";
    echo json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";
    
    echo "=== FIELDS BEING SENT ===\n";
    foreach ($payload as $key => $value) {
        if ($key === 'JSON') {
            echo "✅ {$key}: [JSON string with extraction data]\n";
        } else {
            $displayValue = is_string($value) ? "'{$value}'" : json_encode($value);
            echo "- {$key}: {$displayValue}\n";
        }
    }
    
    // Check if we're properly extracting from the normalized data
    echo "\n=== DATA EXTRACTION CHECK ===\n";
    
    // Manually check what's in the extraction data
    if (isset($extractedData['vehicle'])) {
        echo "Vehicle data found:\n";
        echo "- Brand: " . ($extractedData['vehicle']['brand'] ?? 'NOT SET') . "\n";
        echo "- Model: " . ($extractedData['vehicle']['model'] ?? 'NOT SET') . "\n";
    }
    
    if (isset($extractedData['shipment'])) {
        echo "\nShipment data found:\n";
        echo "- Origin: " . ($extractedData['shipment']['origin'] ?? 'NOT SET') . "\n";
        echo "- Destination: " . ($extractedData['shipment']['destination'] ?? 'NOT SET') . "\n";
    }
    
    if (isset($extractedData['contact'])) {
        echo "\nContact data found:\n";
        echo "- Phone: " . ($extractedData['contact']['phone'] ?? 'NOT SET') . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

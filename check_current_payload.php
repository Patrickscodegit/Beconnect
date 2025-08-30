<?php

use App\Models\Document;
use App\Services\RobawsIntegrationService;

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "🚨 EMERGENCY CHECK - What's being sent to Robaws?\n";
echo "================================================\n\n";

// Get a document with extraction
$document = Document::whereHas('extractions', function($q) {
    $q->where('status', 'completed')->whereNotNull('extraction_data');
})->latest()->first();

if (!$document) {
    echo "❌ No document found\n";
    exit;
}

echo "Document: {$document->filename}\n";
$extraction = $document->extractions()->where('status', 'completed')->latest()->first();
$extractedData = is_string($extraction->extraction_data) 
    ? json_decode($extraction->extraction_data, true) 
    : $extraction->extraction_data;

// Create service and test payload
$service = app(RobawsIntegrationService::class);
$reflection = new ReflectionClass($service);
$method = $reflection->getMethod('buildOfferPayload');
$method->setAccessible(true);

try {
    // Test with the parameters the method expects
    $payload = $method->invoke($service, $extractedData, 1, $extraction);
    
    echo "=== CURRENT PAYLOAD STRUCTURE ===\n";
    foreach ($payload as $key => $value) {
        if ($key === 'JSON') {
            $jsonLength = strlen($value);
            $jsonValid = json_decode($value) !== null;
            echo "✅ JSON: {$jsonLength} chars, valid: " . ($jsonValid ? "YES" : "NO") . "\n";
            if (!$jsonValid) {
                echo "   Raw JSON start: " . substr($value, 0, 100) . "...\n";
            }
        } else {
            $display = is_string($value) ? substr($value, 0, 50) : json_encode($value);
            echo "- {$key}: {$display}\n";
        }
    }
    
    echo "\n=== CHECKING CRITICAL ISSUES ===\n";
    
    // Check if JSON field exists and is valid
    if (!isset($payload['JSON'])) {
        echo "❌ CRITICAL: JSON field is missing!\n";
    } else if (empty($payload['JSON'])) {
        echo "❌ CRITICAL: JSON field is empty!\n";
    } else if (json_decode($payload['JSON']) === null) {
        echo "❌ CRITICAL: JSON field contains invalid JSON!\n";
    } else {
        echo "✅ JSON field is present and valid\n";
    }
    
    // Check total field count
    echo "Total fields: " . count($payload) . "\n";
    
} catch (Exception $e) {
    echo "❌ ERROR in buildOfferPayload: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n🚨 URGENT: Need to fix this immediately!\n";

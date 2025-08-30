<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;
use App\Services\RobawsIntegrationService;
use Illuminate\Support\Facades\Log;

echo "Testing Enhanced Integration Fix\n";
echo "================================\n\n";

$document = Document::find(5);
if (!$document) {
    echo "Document 5 not found\n";
    exit(1);
}

echo "Document ID: " . $document->id . "\n";
echo "Current sync status: " . $document->robaws_sync_status . "\n\n";

// Check extraction
$extraction = $document->extractions()->first();
if ($extraction) {
    echo "✓ Extraction found (ID: " . $extraction->id . ")\n";
    echo "✓ Raw JSON length: " . strlen($extraction->raw_json ?? '') . " chars\n\n";
} else {
    echo "✗ No extraction found\n";
    exit(1);
}

// Test main service
echo "Testing RobawsIntegrationService...\n";
$service = app(RobawsIntegrationService::class);

try {
    $result = $service->createOfferFromDocument($document);
    
    if ($result) {
        echo "✓ Service returned result\n";
        echo "Result structure: " . json_encode(array_keys($result), JSON_PRETTY_PRINT) . "\n";
        
        if (isset($result['success']) && $result['success']) {
            echo "✓ SUCCESS: Offer created\n";
            echo "Offer ID: " . ($result['offer']['id'] ?? 'Unknown') . "\n";
        } else {
            echo "✗ FAILED: Service returned unsuccessful result\n";
            echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
        }
    } else {
        echo "✗ FAILED: Service returned null\n";
    }
    
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\nDone.\n";

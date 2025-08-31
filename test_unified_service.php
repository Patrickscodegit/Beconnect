<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;

echo "🚀 Testing unified Enhanced service integration...\n\n";

// Test 1: Check the container alias works
echo "1. Testing container alias...\n";
try {
    $oldServiceInstance = app(\App\Services\RobawsIntegrationService::class);
    $newServiceInstance = app(\App\Services\RobawsIntegration\EnhancedRobawsIntegrationService::class);
    
    echo "✅ Old service class: " . get_class($oldServiceInstance) . "\n";
    echo "✅ New service class: " . get_class($newServiceInstance) . "\n";
    echo "✅ Same instance: " . ($oldServiceInstance === $newServiceInstance ? 'YES' : 'NO') . "\n";
    
    // Test that the old service has the new createOfferFromDocument method
    if (method_exists($oldServiceInstance, 'createOfferFromDocument')) {
        echo "✅ createOfferFromDocument method available on old service\n";
    } else {
        echo "❌ createOfferFromDocument method NOT available on old service\n";
    }
    
} catch (\Throwable $e) {
    echo "❌ Container alias test failed: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: Search for a document with routing data to test
echo "2. Finding document with routing data for test...\n";
$document = Document::whereNotNull('robaws_quotation_data')
    ->first();

if ($document) {
    echo "✅ Found test document: " . $document->filename . "\n";
    echo "   Document ID: " . $document->id . "\n";
    echo "   Current status: " . $document->robaws_sync_status . "\n";
    echo "   Current quotation ID: " . ($document->robaws_quotation_id ?? 'NULL') . "\n";
    
    $data = $document->robaws_quotation_data;
    if ($data) {
        echo "   Customer: " . ($data['customer'] ?? 'NULL') . "\n";
        echo "   Customer ref: " . ($data['customer_reference'] ?? 'NULL') . "\n";
        echo "   POR: " . ($data['por'] ?? 'NULL') . "\n";
        echo "   POL: " . ($data['pol'] ?? 'NULL') . "\n";
        echo "   POD: " . ($data['pod'] ?? 'NULL') . "\n";
    }
    
    // Test 3: Test the unified service methods
    echo "\n3. Testing unified service methods...\n";
    try {
        $service = app(\App\Services\RobawsIntegration\EnhancedRobawsIntegrationService::class);
        
        // Test method availability
        $methods = ['processDocument', 'createOfferFromDocument', 'processDocumentFromExtraction', 'exportDocumentForRobaws'];
        foreach ($methods as $method) {
            if (method_exists($service, $method)) {
                echo "✅ Method available: " . $method . "\n";
            } else {
                echo "❌ Method missing: " . $method . "\n";
            }
        }
        
    } catch (\Throwable $e) {
        echo "❌ Service method test failed: " . $e->getMessage() . "\n";
    }
    
} else {
    echo "❌ No test document found with routing data\n";
}

echo "\n✅ Integration test completed!\n";

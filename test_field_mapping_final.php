<?php

require_once 'vendor/autoload.php';

use App\Models\Document;
use App\Services\RobawsIntegrationService;
use App\Services\RobawsClient;

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "🔍 Testing Individual Field Mapping - Same Pattern as JSON Field\n";
echo "======================================================================\n";

try {
    // Get the latest document with extracted data
    $document = Document::where('extraction_data', '!=', null)
        ->orderBy('created_at', 'desc')
        ->first();

    if (!$document) {
        echo "❌ No documents found with extraction data\n";
        exit;
    }

    echo "📄 Testing document: {$document->filename}\n";

    // Get the extracted data
    $extractedData = json_decode($document->extraction_data, true);
    
    echo "📊 Extracted data keys: " . implode(', ', array_keys($extractedData)) . "\n";
    
    // Show some sample data structure
    if (isset($extractedData['vehicle'])) {
        echo "🚗 Vehicle data keys: " . implode(', ', array_keys($extractedData['vehicle'])) . "\n";
    }
    
    if (isset($extractedData['shipment'])) {
        echo "📦 Shipment data keys: " . implode(', ', array_keys($extractedData['shipment'])) . "\n";
    }
    
    // Check if JSON field exists (the one that's working)
    $hasJsonField = isset($extractedData['JSON']);
    echo "✅ JSON field exists: " . ($hasJsonField ? "YES" : "NO") . "\n";
    
    if ($hasJsonField) {
        echo "📝 JSON field content length: " . strlen($extractedData['JSON']) . " characters\n";
    }

    // Load field mapping configuration
    $mappingConfig = json_decode(file_get_contents(base_path('config/robaws-field-mapping.json')), true);
    echo "🗺️ Field mapping config loaded with " . count($mappingConfig) . " field mappings\n";

    // Initialize the service with a mock RobawsClient
    $mockClient = new class extends RobawsClient {
        public function __construct() {}
        public function createOffer(array $payload): array {
            echo "🚀 Mock Robaws API call with payload:\n";
            echo "   - clientId: " . ($payload['clientId'] ?? 'NOT SET') . "\n";
            echo "   - name: " . ($payload['name'] ?? 'NOT SET') . "\n";
            
            // Check individual fields at root level (same as JSON)
            $customFields = ['Customer', 'POR', 'POL', 'POD', 'CARGO'];
            foreach ($customFields as $field) {
                echo "   - {$field}: " . ($payload[$field] ?? 'NOT SET') . "\n";
            }
            
            echo "   - JSON field length: " . (isset($payload['JSON']) ? strlen($payload['JSON']) : 'NOT SET') . "\n";
            
            return ['id' => 'mock-offer-123', 'status' => 'created'];
        }
    };

    $service = new RobawsIntegrationService($mockClient);

    // Create the offer
    echo "\n🔄 Creating Robaws offer...\n";
    try {
        $result = $service->createOfferFromDocument($document);

        if ($result) {
            echo "✅ Offer created successfully!\n";
            echo "📋 Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
        } else {
            echo "❌ Failed to create offer\n";
        }
    } catch (Exception $e) {
        echo "❌ Exception during offer creation: " . $e->getMessage() . "\n";
        echo "📍 File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "📍 File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "🔍 Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n🏁 Test completed!\n";

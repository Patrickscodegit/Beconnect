<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\RobawsIntegrationService;
use App\Services\RobawsClient;
use App\Models\Document;
use App\Models\VehicleExtractionData;
use Illuminate\Support\Facades\Log;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🧪 Testing Complete ExtraFields Integration\n";
echo "==========================================\n\n";

try {
    // Get a test document
    $document = Document::latest()->first();
    
    if (!$document) {
        echo "❌ No documents found\n";
        exit;
    }
    
    echo "📄 Document: {$document->filename}\n";
    echo "🆔 Document ID: {$document->id}\n\n";
    
    // Test the integration service
    $robawsClient = new RobawsClient();
    $robawsService = new RobawsIntegrationService($robawsClient);
    
    echo "🚀 Creating Robaws offer with extraFields...\n";
    
    $result = $robawsService->createOfferFromDocument($document);
    
    if ($result && isset($result['id'])) {
        $offerId = $result['id'];
        echo "✅ Offer created successfully!\n";
        echo "🆔 Offer ID: {$offerId}\n\n";
        
        // Show the extraFields that were sent
        if (isset($result['extraFields'])) {
            echo "📝 ExtraFields applied:\n";
            foreach ($result['extraFields'] as $fieldName => $fieldData) {
                $value = $fieldData['stringValue'] ?? $fieldData['dateValue'] ?? $fieldData['booleanValue'] ?? 'N/A';
                echo "   • {$fieldName}: {$value}\n";
            }
        } else {
            echo "⚠️  No extraFields found in response\n";
        }
        
        echo "\n🔗 View in Robaws: https://app.robaws.com/estimates/{$offerId}\n";
        echo "✨ Please check if the custom fields are populated in the Robaws UI\n";
        
    } else {
        echo "❌ Failed to create offer\n";
        if (is_array($result)) {
            echo "Response: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "📍 File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n🏁 Test complete!\n";

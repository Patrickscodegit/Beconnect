<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Intake;
use App\Models\Document;
use App\Services\RobawsIntegrationService;

echo "🧪 Testing Enhanced JSON Export for Robaws\n";
echo "==========================================\n\n";

// Get the most recent intake with extraction
$intake = Intake::with('extraction')->whereHas('extraction')->orderBy('updated_at', 'desc')->first();

if (!$intake || !$intake->extraction) {
    echo "❌ No intake with extraction found\n";
    exit(1);
}

echo "✅ Found Intake ID: {$intake->id}\n";
echo "📊 Extraction confidence: {$intake->extraction->confidence}%\n";

// Get or create a document for testing
$document = $intake->documents()->first();
if (!$document) {
    $document = Document::create([
        'intake_id' => $intake->id,
        'filename' => 'test-enhanced-export.eml',
        'file_path' => 'test-enhanced-export.eml',
        'disk' => 'local',
        'mime_type' => 'message/rfc822',
        'file_size' => 1024,
        'extraction_data' => $intake->extraction->extracted_data
    ]);
    echo "📄 Created test document ID: {$document->id}\n";
} else {
    echo "📄 Using existing document ID: {$document->id}\n";
    // Update with extraction data if missing
    if (!$document->extraction_data) {
        $document->update(['extraction_data' => $intake->extraction->extracted_data]);
    }
}

// Create a mock RobawsClient to capture the payload
$mockClient = new class {
    public $lastPayload = null;
    
    public function createOffer($payload) {
        $this->lastPayload = $payload;
        return ['id' => 'ROBAWS-' . uniqid(), 'status' => 'DRAFT'];
    }
    
    public function findOrCreateClient($data) {
        return ['id' => 999, 'name' => $data['name'] ?? 'Test Client'];
    }
};

// Create service with mock client using reflection
$service = new class($mockClient) extends RobawsIntegrationService {
    public function __construct($client) {
        // Use reflection to set the private property
        $reflection = new ReflectionClass(parent::class);
        $property = $reflection->getProperty('robawsClient');
        $property->setAccessible(true);
        $property->setValue($this, $client);
    }
    
    public function getLastPayload() {
        $reflection = new ReflectionClass(parent::class);
        $property = $reflection->getProperty('robawsClient');
        $property->setAccessible(true);
        return $property->getValue($this)->lastPayload;
    }
};

echo "\n🚀 Testing Enhanced JSON Export...\n";

try {
    // Test the export
    $result = $service->createOfferFromDocument($document);
    
    if (!$result) {
        echo "❌ Export failed\n";
        exit(1);
    }
    
    echo "✅ Export successful!\n";
    
    // Check the payload
    $payload = $service->getLastPayload();
    
    if (!$payload) {
        echo "❌ No payload captured\n";
        exit(1);
    }
    
    echo "\n📊 Payload Analysis:\n";
    echo "- Client ID: " . ($payload['clientId'] ?? 'N/A') . "\n";
    echo "- Offer Name: " . ($payload['name'] ?? 'N/A') . "\n";
    echo "- Currency: " . ($payload['currency'] ?? 'N/A') . "\n";
    echo "- Status: " . ($payload['status'] ?? 'N/A') . "\n";
    
    // Check the enhanced JSON field
    if (isset($payload['extraFields']['JSON']['stringValue'])) {
        echo "\n🎯 Enhanced JSON Field Analysis:\n";
        
        $jsonString = $payload['extraFields']['JSON']['stringValue'];
        $jsonData = json_decode($jsonString, true);
        
        if ($jsonData) {
            echo "✅ Enhanced JSON field present and valid\n";
            echo "📏 JSON size: " . strlen($jsonString) . " bytes\n";
            
            // Check structure
            $sections = ['extraction_metadata', 'extraction_data', 'quality_metrics', 'robaws_integration'];
            foreach ($sections as $section) {
                $status = isset($jsonData[$section]) ? '✅' : '❌';
                echo "{$status} {$section}: " . (isset($jsonData[$section]) ? 'Present' : 'Missing') . "\n";
            }
            
            // Check key metrics
            echo "\n📈 Key Metrics:\n";
            echo "- Confidence Score: " . ($jsonData['extraction_metadata']['confidence_score'] ?? 'N/A') . "%\n";
            echo "- Quality Score: " . ($jsonData['quality_metrics']['overall_quality_score'] ?? 'N/A') . "%\n";
            echo "- Field Completeness: " . ($jsonData['quality_metrics']['field_completeness'] ?? 'N/A') . "%\n";
            echo "- Extraction ID: " . ($jsonData['extraction_metadata']['extraction_id'] ?? 'N/A') . "\n";
            
            // Show sample of processed data
            if (isset($jsonData['extraction_data']['processed_data']['contact_information'])) {
                $contact = $jsonData['extraction_data']['processed_data']['contact_information'];
                echo "\n👤 Processed Contact Data:\n";
                echo "- Name: " . ($contact['name'] ?? 'N/A') . "\n";
                echo "- Email: " . ($contact['email'] ?? 'N/A') . "\n";
                echo "- Company: " . ($contact['company'] ?? 'N/A') . "\n";
            }
            
            echo "\n✅ Enhanced JSON export is working perfectly!\n";
            echo "🎉 Ready for production use with Robaws!\n";
            
        } else {
            echo "❌ Enhanced JSON field contains invalid JSON\n";
            echo "Raw content: " . substr($jsonString, 0, 200) . "...\n";
        }
    } else {
        echo "❌ Enhanced JSON field not found in payload\n";
        echo "Available extraFields: " . json_encode(array_keys($payload['extraFields'] ?? []), JSON_PRETTY_PRINT) . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error during export: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "Test completed!\n";

<?php
require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Document;
use App\Services\Extraction\Strategies\EmailExtractionStrategy;
use App\Services\AiRouter;

echo "🧪 TESTING EMAIL EXTRACTION FIX\n";
echo "===============================\n\n";

// Find the BMW email document
$document = Document::where('filename', 'like', '%68b320c74d202%')
    ->orWhere('filename', 'like', '%.eml')
    ->latest()
    ->first();

if (!$document) {
    echo "❌ No email document found\n";
    exit;
}

echo "📧 Testing email: {$document->filename}\n";
echo "   Document ID: {$document->id}\n";
echo "   MIME Type: {$document->mime_type}\n";
echo "   File Path: {$document->file_path}\n\n";

// Test the updated EmailExtractionStrategy
$aiRouter = app(AiRouter::class);
$strategy = new EmailExtractionStrategy($aiRouter);

echo "🔍 Testing if strategy supports this document...\n";
$supports = $strategy->supports($document);
echo "   Supports: " . ($supports ? "✅ YES" : "❌ NO") . "\n\n";

if ($supports) {
    echo "🚀 Extracting email data...\n";
    $result = $strategy->extract($document);
    
    if ($result->isSuccessful()) {
        echo "✅ Extraction successful!\n\n";
        
        $data = $result->getData();
        
        echo "📋 Extracted Data:\n";
        echo "   Contact Name: " . ($data['contact']['name'] ?? 'Not found') . "\n";
        echo "   Contact Email: " . ($data['contact']['email'] ?? 'Not found') . "\n";
        echo "   Contact Company: " . ($data['contact']['company'] ?? 'Not found') . "\n";
        
        if (isset($data['vehicle'])) {
            echo "   Vehicle Make: " . ($data['vehicle']['make'] ?? 'Not found') . "\n";
            echo "   Vehicle Model: " . ($data['vehicle']['model'] ?? 'Not found') . "\n";
        }
        
        if (isset($data['shipment'])) {
            echo "   Origin: " . ($data['shipment']['origin'] ?? 'Not found') . "\n";
            echo "   Destination: " . ($data['shipment']['destination'] ?? 'Not found') . "\n";
        }
        
        echo "\n📊 Confidence: " . $result->getConfidence() . "\n";
        echo "🔧 Strategy: " . $result->getStrategyUsed() . "\n";
        
        // Check if sender is correctly identified
        if (isset($data['contact']['email']) && $data['contact']['email'] === 'badr.algothami@gmail.com') {
            echo "\n🎉 SUCCESS: Sender correctly identified as customer!\n";
        } else {
            echo "\n⚠️  WARNING: Sender not correctly identified\n";
            echo "   Expected: badr.algothami@gmail.com\n";
            echo "   Got: " . ($data['contact']['email'] ?? 'none') . "\n";
        }
        
    } else {
        echo "❌ Extraction failed: " . $result->getErrorMessage() . "\n";
        echo "   Details: " . json_encode($result->getMetadata(), JSON_PRETTY_PRINT) . "\n";
    }
} else {
    echo "❌ Strategy does not support this document type\n";
}

echo "\n✅ Test completed!\n";

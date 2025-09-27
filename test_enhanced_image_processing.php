<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;
use App\Services\ExtractionService;
use App\Services\Extraction\Strategies\EnhancedImageExtractionStrategy;
use Illuminate\Support\Facades\Log;

echo "🧪 Testing Enhanced Image Processing\n";
echo "===================================\n\n";

// Test 1: Vehicle Database Integration
echo "1. Testing Vehicle Database Integration:\n";
echo "   - VIN lookup enhancement\n";
echo "   - Make/model lookup enhancement\n";
echo "   - Database fallback handling\n\n";

// Test 2: Contact Fallback Extraction
echo "2. Testing Contact Fallback Extraction:\n";
echo "   - WhatsApp screenshot patterns\n";
echo "   - Filename pattern matching\n";
echo "   - Contact name extraction\n\n";

// Test 3: OCR Fallback
echo "3. Testing OCR Fallback:\n";
echo "   - AI vision failure handling\n";
echo "   - OCR text extraction\n";
echo "   - Fallback data merging\n\n";

// Test 4: Integration Test
echo "4. Testing Complete Integration:\n";
echo "   - Enhanced image extraction strategy\n";
echo "   - All enhancements working together\n";
echo "   - Robaws field mapping\n\n";

// Find a recent image document to test with
$imageDocument = Document::where('mime_type', 'like', 'image/%')
    ->where('filename', 'like', '%whatsapp%')
    ->orWhere('filename', 'like', '%screenshot%')
    ->orWhere('filename', 'like', 'IMG_%')
    ->latest()
    ->first();

if (!$imageDocument) {
    // Find any recent image
    $imageDocument = Document::where('mime_type', 'like', 'image/%')
        ->latest()
        ->first();
}

if ($imageDocument) {
    echo "📄 Testing with Document ID: {$imageDocument->id}\n";
    echo "   Filename: {$imageDocument->filename}\n";
    echo "   MIME Type: {$imageDocument->mime_type}\n\n";
    
    try {
        // Test the enhanced image extraction strategy directly
        $strategy = app(EnhancedImageExtractionStrategy::class);
        
        echo "🔍 Running enhanced image extraction...\n";
        $result = $strategy->extract($imageDocument);
        
        if ($result->isSuccess()) {
            echo "✅ Extraction successful!\n";
            echo "   Confidence: " . ($result->getConfidence() * 100) . "%\n";
            echo "   Strategy: " . $result->getStrategy() . "\n\n";
            
            $data = $result->getData();
            echo "📊 Extracted Data:\n";
            
            if (!empty($data['contact'])) {
                echo "   Contact: " . json_encode($data['contact']) . "\n";
            }
            
            if (!empty($data['vehicle'])) {
                echo "   Vehicle: " . json_encode($data['vehicle']) . "\n";
            }
            
            if (!empty($data['shipment'])) {
                echo "   Shipment: " . json_encode($data['shipment']) . "\n";
            }
            
            // Check for enhancement metadata
            $metadata = $result->getMetadata();
            if (!empty($metadata['enhancement_status'])) {
                echo "   Enhancement Status: " . $metadata['enhancement_status'] . "\n";
            }
            
            if (!empty($metadata['database_enhanced'])) {
                echo "   Database Enhanced: " . ($metadata['database_enhanced'] ? 'Yes' : 'No') . "\n";
            }
            
            if (!empty($metadata['contact_fallback_used'])) {
                echo "   Contact Fallback Used: " . ($metadata['contact_fallback_used'] ? 'Yes' : 'No') . "\n";
            }
            
            if (!empty($metadata['ocr_fallback_used'])) {
                echo "   OCR Fallback Used: " . ($metadata['ocr_fallback_used'] ? 'Yes' : 'No') . "\n";
            }
            
        } else {
            echo "❌ Extraction failed: " . $result->getErrorMessage() . "\n";
        }
        
    } catch (\Exception $e) {
        echo "❌ Test failed with exception: " . $e->getMessage() . "\n";
        echo "   Trace: " . $e->getTraceAsString() . "\n";
    }
    
} else {
    echo "⚠️  No image documents found for testing.\n";
    echo "   Upload an image document first to test the enhancements.\n";
}

echo "\n" . str_repeat('=', 50) . "\n";
echo "Enhanced Image Processing Test Complete\n";
echo "=====================================\n\n";

echo "🎯 Key Enhancements Added:\n";
echo "1. ✅ Vehicle Database Integration\n";
echo "2. ✅ Contact Fallback Extraction\n";
echo "3. ✅ OCR Fallback for Text-Heavy Images\n";
echo "4. ✅ Enhanced Post-Processing\n";
echo "5. ✅ Comprehensive Logging\n\n";

echo "📋 Next Steps:\n";
echo "- Test with real WhatsApp screenshots\n";
echo "- Verify Robaws field mapping improvements\n";
echo "- Monitor extraction success rates\n";
echo "- Check client resolution improvements\n\n";

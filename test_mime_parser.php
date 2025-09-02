<?php

// Test the upgraded EmailExtractionStrategy with MIME parser
use App\Services\Extraction\Strategies\EmailExtractionStrategy;
use App\Services\Extraction\HybridExtractionPipeline;
use App\Services\AiRouter;
use App\Models\Document;

echo "🧪 Testing Upgraded EmailExtractionStrategy with MIME Parser\n";
echo "===========================================================\n\n";

$sourceEmlPath = '/Users/patrickhome/Downloads/68b320c74d202_01K3XVG2MBHB27G6HHDHBT47RX.eml';

if (!file_exists($sourceEmlPath)) {
    echo "❌ Source EML file not found: {$sourceEmlPath}\n";
    exit(1);
}

// Copy to workspace for testing
$testEmlPath = 'storage/app/test_bmw_mime.eml';
copy($sourceEmlPath, $testEmlPath);
echo "📧 Copied BMW email for MIME parser testing\n\n";

try {
    // Create test document
    $testDocument = new Document([
        'id' => 99999,
        'filename' => 'test_bmw_mime.eml',
        'file_path' => $testEmlPath,
        'storage_disk' => 'local',
        'mime_type' => 'message/rfc822'
    ]);

    // Initialize services
    $aiRouter = app(AiRouter::class);
    $hybridPipeline = app(HybridExtractionPipeline::class);
    $emailStrategy = new EmailExtractionStrategy($aiRouter, $hybridPipeline);

    echo "🔍 Testing MIME parser extraction...\n";
    $result = $emailStrategy->extract($testDocument);

    if (!$result->isSuccessful()) {
        echo "❌ Extraction failed\n";
        if (method_exists($result, 'getError')) {
            echo "Error: " . $result->getError() . "\n";
        }
        exit(1);
    }

    echo "✅ MIME parser extraction successful!\n\n";
    
    $data = $result->getData();
    $metadata = $result->getMetadata();
    
    // Test specific fields
    echo "📋 CRITICAL TEST RESULTS:\n";
    echo "========================\n";
    
    $contactEmail = data_get($data, 'contact.email');
    $contactName = data_get($data, 'contact.name');
    $expectedEmail = 'badr.algothami@gmail.com';
    $expectedName = 'Badr Algothami';
    
    echo "👤 Contact Information:\n";
    echo "   Name: " . ($contactName ?: 'NOT FOUND') . "\n";
    echo "   Email: " . ($contactEmail ?: 'NOT FOUND') . "\n";
    echo "   Expected Email: {$expectedEmail}\n";
    echo "   Expected Name: {$expectedName}\n\n";
    
    // Email metadata check
    if (isset($metadata['email_metadata'])) {
        echo "📧 Email Metadata:\n";
        echo "   From: " . $metadata['email_metadata']['from'] . "\n";
        echo "   Subject: " . $metadata['email_metadata']['subject'] . "\n\n";
    }
    
    // Vehicle info
    if (isset($data['vehicle'])) {
        echo "🚗 Vehicle Detection:\n";
        echo "   Brand: " . ($data['vehicle']['brand'] ?? 'NOT FOUND') . "\n";
        echo "   Model: " . ($data['vehicle']['model'] ?? 'NOT FOUND') . "\n\n";
    }
    
    // Shipment info
    if (isset($data['shipment'])) {
        echo "🚢 Shipment Detection:\n";
        echo "   Origin: " . ($data['shipment']['origin'] ?? 'NOT FOUND') . "\n";
        echo "   Destination: " . ($data['shipment']['destination'] ?? 'NOT FOUND') . "\n\n";
    }
    
    // Final assessment
    echo "🎯 FINAL ASSESSMENT:\n";
    echo "==================\n";
    
    $emailMatch = ($contactEmail === $expectedEmail);
    $nameMatch = ($contactName === $expectedName);
    
    if ($emailMatch && $nameMatch) {
        echo "   ✅ PERFECT SUCCESS: Both email and name extracted correctly!\n";
        echo "   📊 Status: Ready for production - no more 'needs_contact'\n";
    } elseif ($emailMatch) {
        echo "   ✅ EMAIL SUCCESS: Contact email extracted correctly\n";
        echo "   ⚠️  Name: " . ($contactName ?: 'Not perfect but email works') . "\n";
    } else {
        echo "   ❌ FAILURE: Email extraction still needs work\n";
        echo "   📧 Got: " . ($contactEmail ?: 'NULL') . "\n";
    }
    
    echo "   🏆 Confidence: " . number_format($result->getConfidence() * 100, 1) . "%\n";
    echo "   🔧 Strategy: " . $result->getStrategy() . "\n";

} catch (\Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
    echo "📍 File: " . $e->getFile() . ":" . $e->getLine() . "\n";
} finally {
    // Cleanup
    if (file_exists($testEmlPath)) {
        unlink($testEmlPath);
        echo "\n🧹 Cleaned up test file\n";
    }
}

echo "\n✨ MIME parser upgrade test completed!\n";

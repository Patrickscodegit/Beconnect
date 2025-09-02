<?php

require_once 'bootstrap/app.php';

use App\Services\Extraction\Strategies\EmailExtractionStrategy;
use App\Services\Extraction\HybridExtractionPipeline;
use App\Services\AiRouter;
use App\Models\Document;
use App\Support\DocumentStorage;

echo "🧪 Testing Email Contact Extraction Fix\n";
echo "======================================\n\n";

// Copy the EML file to test
$sourceEmlPath = '/Users/patrickhome/Downloads/68b320c74d202_01K3XVG2MBHB27G6HHDHBT47RX.eml';
$testEmlPath = '/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/test_bmw_email.eml';

if (!file_exists($sourceEmlPath)) {
    echo "❌ Source EML file not found: {$sourceEmlPath}\n";
    exit(1);
}

copy($sourceEmlPath, $testEmlPath);
echo "📧 Copied BMW email for testing\n\n";

// Create a test document
$testDocument = new Document([
    'id' => 999,
    'filename' => 'test_bmw_email.eml',
    'file_path' => $testEmlPath,
    'storage_disk' => 'local',
    'mime_type' => 'message/rfc822'
]);

try {
    // Initialize extraction strategy
    $aiRouter = app(AiRouter::class);
    $hybridPipeline = app(HybridExtractionPipeline::class);
    $emailStrategy = new EmailExtractionStrategy($aiRouter, $hybridPipeline);

    echo "🔍 Testing Email Extraction Strategy...\n";
    
    // Test if strategy supports the document
    if (!$emailStrategy->supports($testDocument)) {
        echo "❌ Strategy does not support this document type\n";
        exit(1);
    }
    
    echo "✅ Strategy supports .eml document\n\n";
    
    // Extract data
    echo "📊 Extracting data from BMW email...\n";
    $result = $emailStrategy->extract($testDocument);
    
    if (!$result->isSuccess()) {
        echo "❌ Extraction failed: " . $result->getError() . "\n";
        echo "Debug info: " . json_encode($result->getDebugInfo(), JSON_PRETTY_PRINT) . "\n";
        exit(1);
    }
    
    echo "✅ Extraction successful!\n\n";
    
    // Display results
    $data = $result->getData();
    $metadata = $result->getMetadata();
    
    echo "📋 EXTRACTION RESULTS:\n";
    echo "=====================\n\n";
    
    // Contact Information
    echo "👤 CONTACT INFORMATION:\n";
    if (isset($data['contact'])) {
        $contact = $data['contact'];
        echo "   Name: " . ($contact['name'] ?? 'NOT FOUND') . "\n";
        echo "   Email: " . ($contact['email'] ?? 'NOT FOUND') . "\n";
        echo "   Phone: " . ($contact['phone'] ?? 'None') . "\n";
        echo "   Company: " . ($contact['company'] ?? 'None') . "\n";
    } else {
        echo "   ❌ No contact data extracted\n";
    }
    echo "\n";
    
    // Email Metadata
    echo "📧 EMAIL METADATA:\n";
    if (isset($metadata['email_metadata'])) {
        $emailMeta = $metadata['email_metadata'];
        echo "   From: " . ($emailMeta['from'] ?? 'NOT FOUND') . "\n";
        echo "   To: " . ($emailMeta['to'] ?? 'NOT FOUND') . "\n";
        echo "   Subject: " . ($emailMeta['subject'] ?? 'NOT FOUND') . "\n";
        echo "   Date: " . ($emailMeta['date'] ?? 'NOT FOUND') . "\n";
    } else {
        echo "   ❌ No email metadata found\n";
    }
    echo "\n";
    
    // Vehicle Information
    echo "🚗 VEHICLE INFORMATION:\n";
    if (isset($data['vehicle'])) {
        $vehicle = $data['vehicle'];
        echo "   Brand: " . ($vehicle['brand'] ?? 'NOT FOUND') . "\n";
        echo "   Model: " . ($vehicle['model'] ?? 'NOT FOUND') . "\n";
        echo "   Year: " . ($vehicle['year'] ?? 'None') . "\n";
    } else {
        echo "   ❌ No vehicle data extracted\n";
    }
    echo "\n";
    
    // Shipment Information
    echo "🚢 SHIPMENT INFORMATION:\n";
    if (isset($data['shipment'])) {
        $shipment = $data['shipment'];
        echo "   Origin: " . ($shipment['origin'] ?? 'NOT FOUND') . "\n";
        echo "   Destination: " . ($shipment['destination'] ?? 'NOT FOUND') . "\n";
        echo "   Shipping Type: " . ($shipment['shipping_type'] ?? 'None') . "\n";
    } else {
        echo "   ❌ No shipment data extracted\n";
    }
    echo "\n";
    
    // Overall Assessment
    echo "🎯 ASSESSMENT:\n";
    echo "==============\n";
    echo "   Confidence: " . number_format($result->getConfidence() * 100, 1) . "%\n";
    echo "   Strategy: " . $result->getStrategy() . "\n";
    
    // Critical test: Is contact email correctly extracted?
    $contactEmail = data_get($data, 'contact.email');
    $expectedEmail = 'badr.algothami@gmail.com';
    
    if ($contactEmail === $expectedEmail) {
        echo "   ✅ SUCCESS: Contact email correctly extracted as customer!\n";
        echo "   📧 Customer: " . data_get($data, 'contact.name', 'Unknown') . " <{$contactEmail}>\n";
    } else {
        echo "   ❌ FAILURE: Contact email not extracted correctly\n";
        echo "   📧 Expected: {$expectedEmail}\n";
        echo "   📧 Got: " . ($contactEmail ?: 'NULL') . "\n";
    }
    
    echo "\n🔍 Full extraction data:\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

} catch (\Exception $e) {
    echo "❌ Exception occurred: " . $e->getMessage() . "\n";
    echo "📍 Stack trace:\n" . $e->getTraceAsString() . "\n";
} finally {
    // Cleanup
    if (file_exists($testEmlPath)) {
        unlink($testEmlPath);
        echo "\n🧹 Cleaned up test file\n";
    }
}

echo "\n✨ Test completed!\n";

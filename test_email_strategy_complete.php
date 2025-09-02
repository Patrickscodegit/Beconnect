<?php

require_once 'vendor/autoload.php';

// Boot Laravel
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;
use App\Services\Extraction\Strategies\EmailExtractionStrategy;
use Illuminate\Support\Facades\Log;

echo "🧪 Testing Complete EmailExtractionStrategy\n";
echo "==========================================\n\n";

// Create a test document similar to how Filament would create it
$sourceEmlPath = '/Users/patrickhome/Downloads/68b320c74d202_01K3XVG2MBHB27G6HHDHBT47RX.eml';

if (!file_exists($sourceEmlPath)) {
    echo "❌ Source EML file not found: {$sourceEmlPath}\n";
    exit(1);
}

try {
    echo "📧 Setting up test document...\n";
    
    // Create test document instance
    $document = new Document([
        'id' => 99999,
        'filename' => 'bmw_serie7_test.eml',
        'mime_type' => 'message/rfc822',
        'file_path' => $sourceEmlPath,
        'storage_disk' => 'local',
        'status' => 'uploaded'
    ]);
    
    echo "🔧 Initializing EmailExtractionStrategy...\n";
    $strategy = app(EmailExtractionStrategy::class);
    
    echo "🚀 Running extraction strategy...\n";
    $result = $strategy->extract($document);
    
    echo "📊 EXTRACTION RESULTS:\n";
    echo "=====================\n";
    
    if ($result->isSuccessful()) {
        echo "   ✅ Status: SUCCESS\n";
        echo "   📈 Confidence: " . $result->getConfidence() . "%\n";
        echo "   🏷️  Strategy: " . $result->getStrategy() . "\n";
        
        $data = $result->getData();
        
        echo "\n👤 CONTACT INFORMATION:\n";
        echo "======================\n";
        if (!empty($data['contact'])) {
            $contact = $data['contact'];
            echo "   📧 Email: " . ($contact['email'] ?? 'NOT FOUND') . "\n";
            echo "   👤 Name: " . ($contact['name'] ?? 'NOT FOUND') . "\n";
            echo "   📞 Phone: " . ($contact['phone'] ?? 'NOT PROVIDED') . "\n";
            echo "   🏢 Company: " . ($contact['company'] ?? 'NOT PROVIDED') . "\n";
        } else {
            echo "   ❌ NO CONTACT DATA EXTRACTED\n";
        }
        
        echo "\n🚗 VEHICLE INFORMATION:\n";
        echo "======================\n";
        if (!empty($data['vehicle'])) {
            $vehicle = $data['vehicle'];
            echo "   🏷️  Make: " . ($vehicle['make'] ?? 'NOT FOUND') . "\n";
            echo "   🏷️  Model: " . ($vehicle['model'] ?? 'NOT FOUND') . "\n";
            echo "   📅 Year: " . ($vehicle['year'] ?? 'NOT FOUND') . "\n";
        } else {
            echo "   ⚠️  NO VEHICLE DATA EXTRACTED\n";
        }
        
        echo "\n📦 SHIPMENT INFORMATION:\n";
        echo "=======================\n";
        if (!empty($data['shipment'])) {
            $shipment = $data['shipment'];
            echo "   🌍 Origin: " . ($shipment['origin'] ?? 'NOT FOUND') . "\n";
            echo "   🌍 Destination: " . ($shipment['destination'] ?? 'NOT FOUND') . "\n";
            echo "   🚢 Type: " . ($shipment['type'] ?? 'NOT FOUND') . "\n";
        } else {
            echo "   ⚠️  NO SHIPMENT DATA EXTRACTED\n";
        }
        
        $metadata = $result->getMetadata();
        echo "\n📋 EMAIL METADATA:\n";
        echo "==================\n";
        if (!empty($metadata['email_metadata'])) {
            $emailMeta = $metadata['email_metadata'];
            echo "   📧 From: " . ($emailMeta['from'] ?? 'NOT FOUND') . "\n";
            echo "   📧 To: " . ($emailMeta['to'] ?? 'NOT FOUND') . "\n";
            echo "   📧 Subject: " . ($emailMeta['subject'] ?? 'NOT FOUND') . "\n";
            echo "   📅 Date: " . ($emailMeta['date'] ?? 'NOT FOUND') . "\n";
        }
        
        // Critical validation
        echo "\n🎯 VALIDATION RESULTS:\n";
        echo "=====================\n";
        
        $expectedEmail = 'badr.algothami@gmail.com';
        $contactEmail = $data['contact']['email'] ?? null;
        
        if ($contactEmail === $expectedEmail) {
            echo "   ✅ Contact Email: PERFECT - " . $contactEmail . "\n";
            echo "   🎉 'needs_contact' status will be RESOLVED\n";
        } else {
            echo "   ❌ Contact Email: FAILED\n";
            echo "       Expected: {$expectedEmail}\n";
            echo "       Got: " . ($contactEmail ?: 'NULL') . "\n";
        }
        
        // Check if we have BMW data
        $vehicleMake = strtolower($data['vehicle']['make'] ?? '');
        if (str_contains($vehicleMake, 'bmw')) {
            echo "   ✅ Vehicle Make: BMW detected correctly\n";
        } else {
            echo "   ⚠️  Vehicle Make: " . ($data['vehicle']['make'] ?? 'NOT DETECTED') . "\n";
        }
        
        echo "\n🏆 FINAL ASSESSMENT:\n";
        echo "===================\n";
        
        if ($contactEmail === $expectedEmail) {
            echo "   🎯 SUCCESS: Email extraction working perfectly\n";
            echo "   📊 Status will change from 'needs_contact' to 'processed'\n";
            echo "   🚀 Ready for production deployment\n";
        } else {
            echo "   ❌ ISSUE: Contact extraction still failing\n";
            echo "   🔧 Additional debugging needed\n";
        }
        
    } else {
        echo "   ❌ Status: FAILED\n";
        echo "   💥 Error: " . $result->getErrorMessage() . "\n";
        echo "   📍 Strategy: " . $result->getStrategy() . "\n";
        
        $metadata = $result->getMetadata();
        if (!empty($metadata)) {
            echo "\n🔍 Error Metadata:\n";
            foreach ($metadata as $key => $value) {
                echo "   {$key}: " . json_encode($value) . "\n";
            }
        }
    }

} catch (\Exception $e) {
    echo "❌ Exception occurred: " . $e->getMessage() . "\n";
    echo "📍 Class: " . get_class($e) . "\n";
    echo "📍 File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n✨ Complete EmailExtractionStrategy test finished!\n";

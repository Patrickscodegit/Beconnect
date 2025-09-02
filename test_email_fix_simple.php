<?php

// Simple test script using existing Laravel setup
use App\Services\EmailService;

echo "🧪 Testing Email Contact Extraction Fix\n";
echo "======================================\n\n";

$sourceEmlPath = '/Users/patrickhome/Downloads/68b320c74d202_01K3XVG2MBHB27G6HHDHBT47RX.eml';

if (!file_exists($sourceEmlPath)) {
    echo "❌ Source EML file not found: {$sourceEmlPath}\n";
    exit(1);
}

echo "📧 Reading BMW email content...\n";
$rawEmail = file_get_contents($sourceEmlPath);

if (empty($rawEmail)) {
    echo "❌ Could not read email content\n";
    exit(1);
}

try {
    // Use the existing EmailDocumentService
    $emailService = app(\App\Services\EmailDocumentService::class);
    
    echo "🔍 Processing email with EmailDocumentService...\n";
    
    // The method expects raw email content and filename
    $result = $emailService->ingestStoredEmail($rawEmail, 'test_bmw_serie7.eml');
    
    if (!$result) {
        echo "❌ Email processing returned null/false\n";
        exit(1);
    }
    
    echo "✅ Email processing completed!\n\n";
    
    // Get the intake record - the service returns the intake directly
    $intake = $result;
    
    if (!$intake || !is_object($intake)) {
        echo "❌ No intake object returned\n";
        var_dump($result);
        exit(1);
    }
    
    echo "📋 INTAKE RESULTS:\n";
    echo "==================\n";
    echo "   ID: " . $intake->id . "\n";
    echo "   Status: " . $intake->status . "\n";
    echo "   Source: " . $intake->source . "\n";
    echo "   Priority: " . $intake->priority . "\n";
    echo "   Customer: " . ($intake->customer_name ?: 'NOT FOUND') . "\n";
    echo "   Email: " . ($intake->customer_email ?: 'NOT FOUND') . "\n";
    echo "   Phone: " . ($intake->customer_phone ?: 'None') . "\n";
    echo "\n";
    
    // Check extraction data
    $extractionData = $intake->extraction_data ?? [];
    if (!empty($extractionData)) {
        echo "🔍 EXTRACTION DATA:\n";
        echo "==================\n";
        
        // Contact
        if (isset($extractionData['contact'])) {
            echo "👤 Contact:\n";
            echo "   Name: " . ($extractionData['contact']['name'] ?? 'NOT FOUND') . "\n";
            echo "   Email: " . ($extractionData['contact']['email'] ?? 'NOT FOUND') . "\n";
            echo "   Phone: " . ($extractionData['contact']['phone'] ?? 'None') . "\n";
            echo "   Company: " . ($extractionData['contact']['company'] ?? 'None') . "\n";
        }
        
        // Vehicle
        if (isset($extractionData['vehicle'])) {
            echo "🚗 Vehicle:\n";
            echo "   Brand: " . ($extractionData['vehicle']['brand'] ?? 'NOT FOUND') . "\n";
            echo "   Model: " . ($extractionData['vehicle']['model'] ?? 'NOT FOUND') . "\n";
            echo "   Year: " . ($extractionData['vehicle']['year'] ?? 'None') . "\n";
        }
        
        // Shipment
        if (isset($extractionData['shipment'])) {
            echo "🚢 Shipment:\n";
            echo "   Origin: " . ($extractionData['shipment']['origin'] ?? 'NOT FOUND') . "\n";
            echo "   Destination: " . ($extractionData['shipment']['destination'] ?? 'NOT FOUND') . "\n";
            echo "   Type: " . ($extractionData['shipment']['shipping_type'] ?? 'None') . "\n";
        }
        echo "\n";
    }
    
    // Critical test: Is contact email correctly extracted?
    $contactEmail = $intake->customer_email;
    $expectedEmail = 'badr.algothami@gmail.com';
    
    echo "🎯 CRITICAL TEST RESULT:\n";
    echo "========================\n";
    
    if ($contactEmail === $expectedEmail) {
        echo "   ✅ SUCCESS: Contact email correctly extracted!\n";
        echo "   📧 Customer: " . ($intake->customer_name ?: 'Unknown') . " <{$contactEmail}>\n";
        echo "   📊 Status: " . $intake->status . " (should be 'processed' or 'pending')\n";
    } else {
        echo "   ❌ FAILURE: Contact email not extracted correctly\n";
        echo "   📧 Expected: {$expectedEmail}\n";
        echo "   📧 Got: " . ($contactEmail ?: 'NULL') . "\n";
        echo "   📊 Status: " . $intake->status . " (likely 'needs_contact')\n";
    }
    
    echo "\n✨ Fix verification: " . ($contactEmail === $expectedEmail ? "SUCCESSFUL" : "NEEDS MORE WORK") . "\n";
    
} catch (\Exception $e) {
    echo "❌ Exception occurred: " . $e->getMessage() . "\n";
    echo "📍 File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n🏁 Test completed!\n";

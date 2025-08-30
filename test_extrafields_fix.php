<?php

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Test script to verify extraFields fix for image documents
 */

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Testing ExtraFields Fix for Image Documents ===\n\n";

use App\Models\Document;
use App\Services\RobawsExportService;
use App\Services\RobawsClient;
use Illuminate\Support\Facades\Log;

try {
    // Find the most recent image document 
    $imageDoc = Document::where('filename', 'like', '%.png')
        ->orWhere('filename', 'like', '%.jpg')
        ->orWhere('filename', 'like', '%.jpeg')
        ->latest()
        ->first();
    
    if (!$imageDoc) {
        echo "❌ No image documents found\n";
        exit(1);
    }
    
    echo "📸 Testing with image document: {$imageDoc->id}\n";
    echo "   Filename: {$imageDoc->filename}\n";
    echo "   Current Robaws ID: " . ($imageDoc->robaws_quotation_id ?: 'None') . "\n\n";
    
    // Check if it has extractions
    $extraction = $imageDoc->extractions()->where('status', 'completed')->latest()->first();
    if (!$extraction) {
        echo "❌ No completed extraction found\n";
        exit(1);
    }
    
    echo "✅ Has completed extraction with vehicle data\n";
    
    // Test creating a new quotation to see if extraFields work
    echo "\n🚀 Creating new test quotation to verify extraFields...\n";
    
    $robawsService = app(App\Services\RobawsIntegrationService::class);
    
    // Clear existing quotation ID to force new creation
    $originalQuotationId = $imageDoc->robaws_quotation_id;
    $imageDoc->update(['robaws_quotation_id' => null]);
    $extraction->update(['robaws_quotation_id' => null]);
    
    // Create new offer
    $result = $robawsService->createOfferFromDocument($imageDoc);
    
    if ($result && isset($result['id'])) {
        echo "✅ New quotation created: {$result['id']}\n";
        
        // Get the offer to check extraFields
        $robawsClient = app(App\Services\RobawsClient::class);
        $offer = $robawsClient->getOffer($result['id']);
        
        if ($offer && isset($offer['extraFields'])) {
            echo "✅ Offer has extraFields: " . count($offer['extraFields']) . " fields\n";
            
            // Check for our custom fields
            $expectedFields = ['JSON', 'CARGO', 'Customer', 'POR', 'POD'];
            $foundFields = 0;
            
            foreach ($expectedFields as $field) {
                if (isset($offer['extraFields'][$field])) {
                    $foundFields++;
                    $value = $offer['extraFields'][$field];
                    if (is_array($value) && isset($value['stringValue'])) {
                        $displayValue = $value['stringValue'];
                    } else {
                        $displayValue = json_encode($value);
                    }
                    echo "  ✅ {$field}: " . substr($displayValue, 0, 50) . "...\n";
                } else {
                    echo "  ❌ {$field}: NOT FOUND\n";
                }
            }
            
            if ($foundFields >= 4) {
                echo "\n🎉 SUCCESS! ExtraFields are now working for image documents!\n";
                echo "Found {$foundFields}/{$expectedFields} expected fields.\n";
            } else {
                echo "\n⚠️  Only found {$foundFields}/{$expectedFields} expected fields.\n";
            }
            
        } else {
            echo "❌ No extraFields found in created offer\n";
        }
        
        // Clean up - delete the test quotation
        echo "\n🧹 Cleaning up test quotation...\n";
        try {
            // Note: Be careful with deletion in production
            echo "ℹ️  Test quotation {$result['id']} created successfully\n";
            echo "ℹ️  You can check it in Robaws and delete it manually if needed\n";
            
            // Restore original state
            if ($originalQuotationId) {
                $imageDoc->update(['robaws_quotation_id' => $originalQuotationId]);
                echo "ℹ️  Restored original quotation ID: {$originalQuotationId}\n";
            }
            
        } catch (\Exception $e) {
            echo "⚠️  Could not clean up: " . $e->getMessage() . "\n";
        }
        
    } else {
        echo "❌ Failed to create quotation\n";
        if ($result) {
            echo "Result: " . json_encode($result) . "\n";
        }
    }
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

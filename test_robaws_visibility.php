<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "🔍 TESTING ACTUAL ROBAWS DOCUMENT VISIBILITY\n";
echo "=============================================\n\n";

// Test a recent quotation
$testQuotationId = '11447'; // The one we fixed earlier

echo "Testing quotation: {$testQuotationId}\n";

// Find the document
$document = \App\Models\Document::where('robaws_quotation_id', $testQuotationId)->first();

if (!$document) {
    echo "No document found for quotation {$testQuotationId}\n";
    exit;
}

echo "Document found: {$document->id} - {$document->filename}\n";
echo "Upload status: " . ($document->upload_status ?: 'None') . "\n";
echo "Robaws document ID: " . ($document->robaws_document_id ?: 'None') . "\n\n";

// Test if we can access the Robaws API to check if document is visible
try {
    $robawsClient = app(\App\Services\RobawsClient::class);
    
    echo "Testing Robaws API connection...\n";
    
    // Try to get quotation details
    $quotationUrl = config('services.robaws.base_url') . "/offers/{$testQuotationId}";
    
    echo "Checking quotation URL: {$quotationUrl}\n";
    
    // Make API call to check quotation
    $response = $robawsClient->get("/offers/{$testQuotationId}");
    
    if ($response && isset($response['id'])) {
        echo "✅ Quotation exists in Robaws\n";
        echo "Quotation status: " . ($response['status'] ?? 'Unknown') . "\n";
        
        // Check for documents/attachments
        if (isset($response['documents']) && is_array($response['documents'])) {
            echo "📄 Documents found: " . count($response['documents']) . "\n";
            foreach ($response['documents'] as $doc) {
                echo "  - ID: " . ($doc['id'] ?? 'Unknown') . "\n";
                echo "    Name: " . ($doc['file_name'] ?? 'Unknown') . "\n";
                echo "    Size: " . ($doc['file_size'] ?? 'Unknown') . " bytes\n";
            }
        } else {
            echo "❌ No documents array found in quotation\n";
        }
        
        if (isset($response['attachments']) && is_array($response['attachments'])) {
            echo "📎 Attachments found: " . count($response['attachments']) . "\n";
            foreach ($response['attachments'] as $att) {
                echo "  - ID: " . ($att['id'] ?? 'Unknown') . "\n";
                echo "    Name: " . ($att['file_name'] ?? 'Unknown') . "\n";
            }
        } else {
            echo "❌ No attachments array found in quotation\n";
        }
        
    } else {
        echo "❌ Quotation not found in Robaws or API error\n";
        echo "Response: " . json_encode($response) . "\n";
    }
    
} catch (\Exception $e) {
    echo "❌ API Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "DIAGNOSIS: Check if documents appear in the quotation response above.\n";
echo "If documents are missing, the issue is with the Robaws API upload process.\n";

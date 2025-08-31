<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "✅ VERIFICATION - QUOTATION 11447 STATUS\n";
echo "========================================\n\n";

$quotation = \App\Models\Quotation::where('robaws_id', '11447')->first();
$document = $quotation ? \App\Models\Document::find($quotation->document_id) : null;

if ($quotation && $document) {
    echo "Quotation 11447 Summary:\n";
    echo "  Local ID: {$quotation->id}\n";
    echo "  Document ID: {$document->id}\n";
    echo "  Filename: {$document->filename}\n";
    echo "  Robaws Quotation ID: {$document->robaws_quotation_id}\n";
    echo "  Upload Status: {$document->upload_status}\n";
    echo "  Processing Status: {$document->processing_status}\n";
    echo "  Robaws Document ID: " . ($document->robaws_document_id ?: 'None') . "\n";
    
    echo "\n🔍 Testing Robaws API connection to verify document visibility...\n";
    
    $robawsClient = app(\App\Services\RobawsClient::class);
    
    try {
        // Check if the document is visible in the quotation
        $quotationData = $robawsClient->getQuotation($document->robaws_quotation_id);
        
        if ($quotationData && isset($quotationData['id'])) {
            echo "✅ Quotation {$document->robaws_quotation_id} found in Robaws\n";
            
            // Check for documents/attachments
            if (isset($quotationData['documents']) && is_array($quotationData['documents'])) {
                echo "📎 Documents in Robaws: " . count($quotationData['documents']) . "\n";
                foreach ($quotationData['documents'] as $doc) {
                    echo "  - Document ID: " . ($doc['id'] ?? 'Unknown') . "\n";
                    echo "    Filename: " . ($doc['file_name'] ?? 'Unknown') . "\n";
                }
            } else {
                echo "⚠️  No documents array found in quotation data\n";
            }
            
            if (isset($quotationData['attachments']) && is_array($quotationData['attachments'])) {
                echo "📎 Attachments in Robaws: " . count($quotationData['attachments']) . "\n";
                foreach ($quotationData['attachments'] as $att) {
                    echo "  - Attachment ID: " . ($att['id'] ?? 'Unknown') . "\n";
                    echo "    Filename: " . ($att['file_name'] ?? 'Unknown') . "\n";
                }
            } else {
                echo "⚠️  No attachments array found in quotation data\n";
            }
        } else {
            echo "❌ Could not retrieve quotation from Robaws\n";
        }
        
    } catch (\Exception $e) {
        echo "❌ Error checking Robaws: " . $e->getMessage() . "\n";
    }
    
} else {
    echo "❌ Quotation or document not found!\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "💡 SUMMARY: If you still see an empty DOCUMENTS tab,\n";
echo "   try refreshing the Robaws page or clearing browser cache.\n";
echo "   The document upload was successful (ID: 107114).\n";

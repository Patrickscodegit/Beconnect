<?php

require_once __DIR__ . '/bootstrap/app.php';

use App\Services\ExtractionService;
use App\Services\Extraction\Strategies\EmailExtractionStrategy;
use App\Models\Document;

echo "=== RO-RO Dimensions Debugging ===\n\n";

// Create a test document for the RO-RO email
$document = new Document([
    'filename' => 'RO-RO verscheping ANTWERPEN - MOMBASA, KENIA.eml',
    'mime_type' => 'message/rfc822',
    'file_path' => '/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/RO-RO verscheping ANTWERPEN - MOMBASA, KENIA.eml'
]);

echo "1. Email Content Analysis:\n";
echo "File: " . $document->filename . "\n\n";

// Read the email content
$emailContent = file_get_contents($document->file_path);
echo "Email contains dimensions:\n";
echo "- L390 cm (Length)\n";
echo "- B230 cm (Width)\n"; 
echo "- H310cm (Height)\n";
echo "- 3500KG (Weight)\n\n";

echo "2. Testing Email Extraction:\n";
try {
    $extractionService = app(ExtractionService::class);
    $emailStrategy = new EmailExtractionStrategy(app(\App\Services\AiRouter::class));
    
    if ($emailStrategy->supports($document)) {
        echo "✓ Email strategy supports this document\n";
        
        // Extract data
        $result = $emailStrategy->extract($document);
        
        if ($result && $result->isSuccess()) {
            $extractedData = $result->getData();
            echo "✓ Extraction successful\n\n";
            
            // Look for dimensions in various paths
            echo "3. Dimension Analysis:\n";
            
            // Check vehicle dimensions
            if (isset($extractedData['vehicle']['dimensions'])) {
                $dims = $extractedData['vehicle']['dimensions'];
                echo "Vehicle dimensions found:\n";
                print_r($dims);
            } else {
                echo "❌ No vehicle dimensions found in structured format\n";
            }
            
            // Check raw dimensions
            if (isset($extractedData['raw_data']['dimensions'])) {
                echo "Raw dimensions: " . $extractedData['raw_data']['dimensions'] . "\n";
            } else {
                echo "❌ No raw dimensions found\n";
            }
            
            // Check for dimensions string
            if (isset($extractedData['dimensions'])) {
                echo "Direct dimensions: " . $extractedData['dimensions'] . "\n";
            } else {
                echo "❌ No direct dimensions found\n";
            }
            
            echo "\n4. Full Extracted Data Structure:\n";
            echo json_encode($extractedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
        } else {
            echo "❌ Extraction failed\n";
            if ($result) {
                echo "Error: " . $result->getErrors()[0] ?? 'Unknown error' . "\n";
            }
        }
    } else {
        echo "❌ Email strategy doesn't support this document\n";
    }
    
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

<?php

require_once 'vendor/autoload.php';

use App\Models\Document;
use App\Services\Extraction\Strategies\ImageExtractionStrategy;
use App\Services\AiRouter;

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Direct Image Strategy Test\n";
echo "==========================\n\n";

// Find a PNG document
$document = Document::where('mime_type', 'image/png')->first();

if (!$document) {
    echo "No PNG document found in database\n";
    exit(1);
}

echo "Testing with document ID: {$document->id}\n";
echo "File: {$document->filename}\n";
echo "MIME: {$document->mime_type}\n";
echo "Path: {$document->file_path}\n\n";

try {
    // Create strategy instance
    $aiRouter = app(AiRouter::class);
    $strategy = new ImageExtractionStrategy($aiRouter);
    
    // Test if strategy supports this document
    if (!$strategy->supports($document)) {
        echo "Strategy does not support this document\n";
        exit(1);
    }
    
    echo "Strategy supports document: YES\n";
    echo "Strategy name: {$strategy->getName()}\n";
    echo "Strategy priority: {$strategy->getPriority()}\n\n";
    
    echo "Running extraction...\n";
    $result = $strategy->extract($document);
    
    echo "=== EXTRACTION RESULTS ===\n";
    echo "Success: " . ($result->isSuccessful() ? 'YES' : 'NO') . "\n";
    echo "Strategy: {$result->getStrategyUsed()}\n";
    echo "Confidence: {$result->getConfidence()}\n";
    
    $extractedData = $result->getData();
    if (!empty($extractedData)) {
        echo "Fields extracted: " . count($extractedData) . "\n";
        echo "Has vehicle data: " . (isset($extractedData['vehicle']) ? 'YES' : 'NO') . "\n";
        echo "Has contact data: " . (isset($extractedData['contact']) ? 'YES' : 'NO') . "\n";
        echo "Has shipment data: " . (isset($extractedData['shipment']) ? 'YES' : 'NO') . "\n";
        
        if (!empty($extractedData)) {
            echo "\nSample extracted data:\n";
            echo json_encode(array_slice($extractedData, 0, 3, true), JSON_PRETTY_PRINT);
        }
    }
    
    $metadata = $result->getMetadata();
    if (!empty($metadata)) {
        echo "\nProcessing time: " . round(($metadata['processing_time'] ?? 0) * 1000, 2) . "ms\n";
        echo "Vision model: " . ($metadata['vision_model'] ?? 'N/A') . "\n";
        echo "File size: " . number_format($metadata['image_file_size'] ?? 0) . " bytes\n";
    }
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Check logs for details.\n";
}

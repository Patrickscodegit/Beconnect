<?php

// Quick test script for VehicleDataEnhancer
require_once __DIR__ . '/vendor/autoload.php';

use App\Models\Document;
use App\Services\Extraction\ExtractionPipeline;

$document = Document::find(21); // The Alfa Giulietta image

if ($document) {
    echo "Testing VehicleDataEnhancer with document {$document->id} ({$document->filename})\n\n";
    
    $pipeline = app(ExtractionPipeline::class);
    $result = $pipeline->process($document);
    
    if ($result->isSuccessful()) {
        $data = $result->getData();
        
        echo "=== EXTRACTION RESULTS ===\n";
        echo "Strategy: {$result->getStrategyUsed()}\n";
        echo "Confidence: {$result->getConfidence()}\n\n";
        
        echo "=== VEHICLE DATA ===\n";
        if (isset($data['vehicle_make'])) echo "Make: {$data['vehicle_make']}\n";
        if (isset($data['vehicle_model'])) echo "Model: {$data['vehicle_model']}\n";
        if (isset($data['vehicle_year'])) echo "Year: {$data['vehicle_year']}\n";
        if (isset($data['weight'])) echo "Weight: {$data['weight']}\n";
        if (isset($data['dimensions'])) echo "Dimensions: {$data['dimensions']}\n";
        if (isset($data['engine_size'])) echo "Engine: {$data['engine_size']} CC\n";
        if (isset($data['calculated_volume'])) echo "Volume: {$data['calculated_volume']}\n";
        if (isset($data['shipping_class'])) echo "Shipping Class: {$data['shipping_class']}\n";
        if (isset($data['recommended_container'])) echo "Recommended Container: {$data['recommended_container']}\n";
        
        echo "\n=== DATA SOURCES ===\n";
        if (isset($data['fields_from_document'])) echo "From Document: {$data['fields_from_document']} fields\n";
        if (isset($data['fields_from_database'])) echo "From Database: {$data['fields_from_database']} fields\n";
        if (isset($data['fields_from_ai'])) echo "From AI: {$data['fields_from_ai']} fields\n";
        if (isset($data['fields_calculated'])) echo "Calculated: {$data['fields_calculated']} fields\n";
        
        if (isset($data['enhancement_confidence'])) echo "Enhancement Confidence: {$data['enhancement_confidence']}\n";
        if (isset($data['enhancement_time_ms'])) echo "Enhancement Time: {$data['enhancement_time_ms']} ms\n";
        
        echo "\n=== SUCCESS ===\n";
    } else {
        echo "Extraction failed: {$result->getError()}\n";
    }
} else {
    echo "Document not found\n";
}

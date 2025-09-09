<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

use App\Models\Intake;
use App\Services\ExtractionService;
use Illuminate\Support\Facades\Log;

echo "🔍 Analyzing Image Extraction Data\n";
echo "==================================\n\n";

// Find recent image intakes
$imageIntakes = Intake::with('files')
    ->whereHas('files', function($query) {
        $query->where('mime_type', 'like', 'image/%');
    })
    ->orderBy('created_at', 'desc')
    ->limit(3)
    ->get();

foreach ($imageIntakes as $intake) {
    echo "📋 Intake ID: {$intake->id}\n";
    echo "Status: {$intake->status}\n";
    echo "Customer Name: " . ($intake->customer_name ?? 'None') . "\n";
    echo "Contact Email: " . ($intake->contact_email ?? 'None') . "\n";
    echo "Contact Phone: " . ($intake->contact_phone ?? 'None') . "\n";
    
    // Show files
    echo "Files:\n";
    foreach ($intake->files as $file) {
        echo "  - {$file->filename} ({$file->mime_type})\n";
    }
    
    // Check if there's extraction data
    $extractionService = app(ExtractionService::class);
    
    try {
        // Get the first image file
        $imageFile = $intake->files->where('mime_type', 'like', 'image/%')->first();
        if ($imageFile) {
            echo "\n🔍 Attempting extraction analysis...\n";
            
            // Try to read stored extraction data if available
            $documents = \App\Models\Document::where('intake_id', $intake->id)->get();
            if ($documents->count() > 0) {
                foreach ($documents as $doc) {
                    echo "\n📄 Document ID: {$doc->id}\n";
                    echo "Extraction Status: " . ($doc->extraction_status ?? 'None') . "\n";
                    
                    if ($doc->extraction_meta) {
                        $meta = json_decode($doc->extraction_meta, true);
                        echo "Extraction Meta Keys: " . implode(', ', array_keys($meta)) . "\n";
                        
                        // Show contact info if available
                        if (isset($meta['contact'])) {
                            echo "Contact Data:\n";
                            foreach ($meta['contact'] as $key => $value) {
                                if ($value) {
                                    echo "  - {$key}: {$value}\n";
                                }
                            }
                        }
                        
                        // Show VIN candidates if available
                        if (isset($meta['vin_candidates']) && is_array($meta['vin_candidates'])) {
                            echo "VIN Candidates:\n";
                            foreach ($meta['vin_candidates'] as $vin) {
                                if (is_array($vin)) {
                                    echo "  - VIN: " . ($vin['vin'] ?? 'Unknown') . " (Confidence: " . ($vin['confidence'] ?? 'N/A') . ")\n";
                                } else {
                                    echo "  - VIN: {$vin}\n";
                                }
                            }
                        }
                        
                        // Show any other interesting fields
                        if (isset($meta['ocr_text'])) {
                            echo "OCR Text Preview: " . substr($meta['ocr_text'], 0, 200) . "...\n";
                        }
                    }
                }
            } else {
                echo "No documents found for this intake.\n";
            }
        }
    } catch (\Exception $e) {
        echo "Error analyzing extraction: " . $e->getMessage() . "\n";
    }
    
    echo "\n" . str_repeat('-', 50) . "\n\n";
}

echo "Analysis completed.\n";

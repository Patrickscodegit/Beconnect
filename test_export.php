<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Intake;
use App\Services\RobawsIntegrationService;
use App\Filament\Resources\IntakeResource;
use App\Models\Document;

echo "=== Testing Export to Robaws Functionality ===\n\n";

try {
    // Get the latest intake with extraction data
    $intake = Intake::with(['documents.extractions'])
        ->whereHas('documents.extractions', function($q) {
            $q->where('status', 'completed');
        })
        ->latest()
        ->first();

    if (!$intake) {
        echo "❌ No intake with completed extraction found\n";
        exit(1);
    }

    echo "✅ Found intake ID: {$intake->id}\n";
    echo "   Status: {$intake->status}\n";

    $document = $intake->documents->first();
    $extraction = $document->extractions->first();

    if (!$extraction || !$extraction->extracted_data) {
        echo "❌ No extraction data found\n";
        exit(1);
    }

    echo "✅ Found extraction ID: {$extraction->id}\n";
    echo "   Document: {$document->filename}\n";
    echo "   Analysis type: {$extraction->analysis_type}\n\n";

    // Test the field mapping
    echo "=== Testing Field Mapping ===\n";
    $mappedData = IntakeResource::mapExtractionDataForRobaws($extraction->extracted_data);
    
    echo "Customer: " . ($mappedData['client_name'] ?? 'N/A') . "\n";
    echo "Email: " . ($mappedData['client_email'] ?? 'N/A') . "\n";
    echo "Route: " . ($mappedData['por'] ?? 'N/A') . " → " . ($mappedData['pod'] ?? 'N/A') . "\n";
    echo "Cargo: " . ($mappedData['cargo_description'] ?? 'N/A') . "\n";
    echo "Vehicle: " . ($mappedData['vehicle_brand'] ?? 'N/A') . " " . ($mappedData['vehicle_model'] ?? 'N/A') . "\n";
    echo "Weight: " . ($mappedData['weight_kg'] ?? 'N/A') . " kg\n";
    echo "Dimensions: " . ($mappedData['dim_bef_delivery'] ?? 'N/A') . "\n\n";

    // Test the Robaws export
    echo "=== Testing Robaws Export ===\n";
    $robawsService = app(RobawsIntegrationService::class);

    // Create a document model with the mapped data
    $testDocument = new Document([
        'filename' => $document->filename,
        'path' => $document->path,
        'file_path' => $document->file_path,
        'disk' => $document->disk ?? config('filesystems.default', 'local'),
        'mime_type' => $document->mime_type,
        'extraction_data' => $mappedData,
        'user_id' => 1,
    ]);
    $testDocument->id = $document->id;

    echo "Attempting to create Robaws offer...\n";
    $result = $robawsService->createOfferFromDocument($testDocument);

    if ($result) {
        echo "✅ Export successful!\n";
        echo "   Robaws offer ID: " . ($result['id'] ?? 'Unknown') . "\n";
        echo "   Client ID: " . ($result['clientId'] ?? 'Unknown') . "\n";
        echo "   Offer name: " . ($result['name'] ?? 'Unknown') . "\n";
        
        // Update the intake with the Robaws reference
        $intake->update([
            'robaws_quotation_id' => $result['id'] ?? null,
            'notes' => ($intake->notes ?? '') . "\n\nTest Export - Robaws Quotation ID: " . ($result['id'] ?? 'Unknown'),
        ]);
        
        echo "✅ Intake updated with Robaws reference\n";
        
    } else {
        echo "❌ Export failed - no result returned\n";
    }

} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    
    // Show more details for debugging
    if (method_exists($e, 'getPrevious') && $e->getPrevious()) {
        echo "   Previous: " . $e->getPrevious()->getMessage() . "\n";
    }
}

echo "\n=== Test Complete ===\n";

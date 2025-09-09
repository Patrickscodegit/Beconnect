<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

use App\Models\Intake;
use App\Services\Robaws\RobawsExportService;
use Illuminate\Support\Facades\Log;

echo "🔍 Email Export Debug\n";
echo "=====================\n\n";

// Get intake 17
$intake = Intake::find(17);
if (!$intake) {
    echo "❌ Intake 17 not found\n";
    exit;
}

echo "📧 Intake Details:\n";
echo "ID: {$intake->id}\n";
echo "Status: {$intake->status}\n";
echo "Customer: {$intake->customer_name}\n";
echo "Email: {$intake->contact_email}\n";
echo "Phone: {$intake->contact_phone}\n";
echo "Robaws Client ID: " . ($intake->robaws_client_id ?? 'None') . "\n\n";

// Check documents and extraction data
$documents = $intake->documents;
echo "📄 Documents: " . $documents->count() . "\n";

foreach ($documents as $doc) {
    echo "\nDocument {$doc->id}:\n";
    echo "  - Filename: {$doc->filename}\n";
    echo "  - Status: {$doc->processing_status}\n";
    echo "  - Extraction: {$doc->extraction_status}\n";
    
    if ($doc->extraction_data) {
        $extractionData = json_decode($doc->extraction_data, true);
        echo "  - Extraction Keys: " . implode(', ', array_keys($extractionData)) . "\n";
        
        if (isset($extractionData['contact'])) {
            echo "  - Contact Data:\n";
            foreach ($extractionData['contact'] as $key => $value) {
                echo "    * {$key}: {$value}\n";
            }
        }
    }
}

echo "\n🔧 Testing Client Resolution:\n";

// Try to resolve the client manually using the extraction data
try {
    $exportService = app(RobawsExportService::class);
    
    // Build extraction data from intake
    $extractionData = [
        'contact' => [
            'name' => $intake->customer_name,
            'email' => $intake->contact_email,
            'phone' => $intake->contact_phone
        ]
    ];
    
    echo "Testing with data: " . json_encode($extractionData, JSON_PRETTY_PRINT) . "\n";
    
    $clientId = $exportService->resolveClientId($extractionData);
    if ($clientId) {
        echo "✅ Client resolved: {$clientId}\n";
    } else {
        echo "❌ Client resolution failed\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Error during client resolution: " . $e->getMessage() . "\n";
}

echo "\nDiagnosis completed.\n";

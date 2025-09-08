<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Intake;
use App\Models\Document;
use App\Services\Extraction\EmailExtractionService;
use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\Export\Mappers\RobawsMapper;

// Initialize Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Testing Lagos Nigeria Email Processing\n";
echo "=====================================\n\n";

try {
    // Read the Lagos Nigeria email file
    $emailPath = __DIR__ . '/lagos_nigeria.eml';
    if (!file_exists($emailPath)) {
        throw new Exception("Lagos Nigeria email file not found at: $emailPath");
    }
    
    $emailContent = file_get_contents($emailPath);
    
    // Create a test intake
    $intake = Intake::create([
        'email_subject' => 'EXPPORT QUOTE FROM ANTWERP TO TIN-CAN PORT , LAGOS NIGERIA',
        'sender_email' => 'ebypounds@gmail.com',
        'sender_name' => 'Ebele Efobi',
        'received_at' => now(),
        'status' => 'pending',
        'priority' => 'normal'
    ]);
    
    echo "Created intake ID: {$intake->id}\n";
    
    // Create a document for this intake
    $document = Document::create([
        'intake_id' => $intake->id,
        'filename' => 'lagos_nigeria.eml',
        'original_filename' => 'lagos_nigeria.eml',
        'mime_type' => 'message/rfc822',
        'file_size' => strlen($emailContent),
        'file_path' => 'test/lagos_nigeria.eml',
        'storage_disk' => 'local'
    ]);
    
    echo "Created document ID: {$document->id}\n";
    
    // Extract using PatternExtractor with VehicleDatabaseService
    $vehicleDb = app(App\Services\VehicleDatabase\VehicleDatabaseService::class);
    $patternExtractor = new PatternExtractor($vehicleDb);
    $extractionResult = $patternExtractor->extract($emailContent, $document);
    
    echo "\n--- EXTRACTION RESULTS ---\n";
    echo json_encode($extractionResult, JSON_PRETTY_PRINT) . "\n";
    
    // Update intake with extraction data
    $intake->update([
        'extraction_data' => $extractionResult,
        'status' => 'extracted'
    ]);
    
    // Now test RobawsMapper
    $robawsMapper = new RobawsMapper();
    $mappedData = $robawsMapper->mapIntakeToRobaws($intake, $extractedData);
    
    echo "\n--- ROBAWS MAPPED DATA ---\n";
    echo json_encode($mappedData, JSON_PRETTY_PRINT) . "\n";
    
    // Key checks for Lagos requirements
    echo "\n--- KEY REQUIREMENTS CHECK ---\n";
    echo "POD (should be 'Lagos, Nigeria'): " . ($mappedData['pod'] ?? 'NOT SET') . "\n";
    echo "Customer Reference (should contain 'EXP RORO - ANR - LAGOS'): " . ($mappedData['customer_reference'] ?? 'NOT SET') . "\n";
    
    // Check if requirements are met
    $podCorrect = isset($mappedData['pod']) && $mappedData['pod'] === 'Lagos, Nigeria';
    $refCorrect = isset($mappedData['customer_reference']) && 
                  str_contains($mappedData['customer_reference'], 'EXP RORO') &&
                  str_contains($mappedData['customer_reference'], 'ANR') &&
                  str_contains($mappedData['customer_reference'], 'LAGOS');
    
    echo "\nRequirements Status:\n";
    echo "✓ POD = 'Lagos, Nigeria': " . ($podCorrect ? 'PASS' : 'FAIL') . "\n";
    echo "✓ Customer Reference contains required elements: " . ($refCorrect ? 'PASS' : 'FAIL') . "\n";
    
    if ($podCorrect && $refCorrect) {
        echo "\n🎉 ALL REQUIREMENTS MET!\n";
    } else {
        echo "\n❌ Some requirements not met. Review the mapping logic.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\nTest completed.\n";

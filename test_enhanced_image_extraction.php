<?php

require_once __DIR__ . '/bootstrap/app.php';

use App\Models\Intake;
use App\Services\IntakeCreationService;
use App\Support\VinDetector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

echo "=== Testing Enhanced Image Extraction ===\n\n";

// Test VIN detection
echo "1. Testing VIN Detection:\n";
$testText = "Vehicle VIN: 1HGBH41JXMN109186 License: ABC-123";
$vins = VinDetector::detect($testText);
echo "   Text: '$testText'\n";
echo "   VINs found: " . (empty($vins) ? 'None' : implode(', ', $vins)) . "\n";

// Test with invalid VIN
$invalidText = "Invalid VIN: 1234567890ABCDEFG";
$invalidVins = VinDetector::detect($invalidText);
echo "   Invalid VIN test: " . (empty($invalidVins) ? 'Correctly rejected' : 'Should not find: ' . implode(', ', $invalidVins)) . "\n\n";

// Create a test image with text (base64 encoded small PNG with text)
echo "2. Testing Image Upload with Enhanced Processing:\n";

// Create a simple test image file
$testImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
$testImagePath = sys_get_temp_dir() . '/test_image_' . uniqid() . '.png';
file_put_contents($testImagePath, $testImageData);

try {
    $uploadedFile = new UploadedFile(
        $testImagePath,
        'test_enhanced_image.png',
        'image/png',
        null,
        true
    );

    $intakeService = app(IntakeCreationService::class);
    
    // Create intake from uploaded file
    $intake = $intakeService->createFromUploadedFile($uploadedFile, [
        'source' => 'test_enhanced',
        'priority' => 'normal',
    ]);

    echo "   Created intake ID: {$intake->id}\n";
    echo "   Initial status: {$intake->status}\n";
    echo "   Source: {$intake->source}\n";
    echo "   Files count: " . $intake->files()->count() . "\n";

    // Wait for processing
    sleep(2);
    
    $intake->refresh();
    echo "   Final status: {$intake->status}\n";
    echo "   Flags: " . (empty($intake->flags) ? 'None' : implode(', ', $intake->flags)) . "\n";
    
    if ($intake->extraction_data) {
        echo "   Extraction data keys: " . implode(', ', array_keys($intake->extraction_data)) . "\n";
        
        // Check for VIN candidates
        if (isset($intake->extraction_data['vin_candidates'])) {
            echo "   VIN candidates found: " . implode(', ', $intake->extraction_data['vin_candidates']) . "\n";
        }
        
        // Check for OCR metadata
        if (isset($intake->extraction_data['ocr_confidence'])) {
            echo "   OCR confidence: " . $intake->extraction_data['ocr_confidence'] . "\n";
        }
        
        if (isset($intake->extraction_data['vision_provider'])) {
            echo "   Vision provider: " . $intake->extraction_data['vision_provider'] . "\n";
        }
    } else {
        echo "   No extraction data yet\n";
    }

} catch (\Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
    echo "   Trace: " . $e->getTraceAsString() . "\n";
} finally {
    // Cleanup
    if (file_exists($testImagePath)) {
        unlink($testImagePath);
    }
}

echo "\n3. Testing Configuration:\n";
$skipTypes = config('intake.processing.skip_contact_validation', []);
echo "   Skip contact validation for: " . implode(', ', $skipTypes) . "\n";

echo "\n=== Enhanced Image Extraction Test Complete ===\n";

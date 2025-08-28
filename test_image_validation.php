<?php

require_once 'vendor/autoload.php';

use App\Services\AiRouter;
use Illuminate\Support\Facades\Log;

// Bootstrap Laravel for testing
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Image Validation Test Script\n";
echo "============================\n\n";

// Check if an image file is provided
if ($argc < 2) {
    echo "Usage: php test_image_validation.php <path_to_image_file>\n";
    echo "Example: php test_image_validation.php /path/to/test.png\n";
    exit(1);
}

$imagePath = $argv[1];

if (!file_exists($imagePath)) {
    echo "Error: Image file not found: $imagePath\n";
    exit(1);
}

echo "Testing image: $imagePath\n";
echo "File size: " . number_format(filesize($imagePath)) . " bytes\n";

// Get MIME type
$mimeType = mime_content_type($imagePath);
echo "MIME type: $mimeType\n";

// Test image info
$imageInfo = getimagesize($imagePath);
if ($imageInfo) {
    echo "Dimensions: {$imageInfo[0]} x {$imageInfo[1]}\n";
    echo "Image type: " . image_type_to_mime_type($imageInfo[2]) . "\n";
} else {
    echo "Warning: Could not get image dimensions\n";
}

echo "\n";

try {
    // Create AiRouter instance
    $aiRouter = app(AiRouter::class);
    
    // Read and encode the image
    $imageContent = file_get_contents($imagePath);
    $base64Image = base64_encode($imageContent);
    
    echo "Base64 length: " . number_format(strlen($base64Image)) . " characters\n";
    echo "Estimated decoded size: " . number_format(strlen($base64Image) * 0.75) . " bytes\n\n";
    
    // Test the vision analysis using the public extractAdvanced method
    echo "Testing OpenAI Vision API via extractAdvanced...\n";
    $extractionInput = [
        'bytes' => $base64Image,
        'mime' => $mimeType,
        'filename' => basename($imagePath)
    ];
    
    $result = $aiRouter->extractAdvanced($extractionInput, 'comprehensive');
    
    echo "Success! API returned response:\n";
    echo "Data keys: " . implode(', ', array_keys($result['data'] ?? [])) . "\n";
    echo "Confidence: " . ($result['confidence'] ?? 'N/A') . "\n";
    echo "Metadata keys: " . implode(', ', array_keys($result['metadata'] ?? [])) . "\n";
    
    if (!empty($result['data'])) {
        echo "\nExtracted data preview:\n";
        echo json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Check the logs for more details.\n";
}

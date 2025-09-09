<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\IntakeCreationService;
use App\Models\Intake;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

echo "🔍 Testing Image Upload Without Contact Validation\n";
echo "=================================================\n\n";

try {
    $intakeService = app(IntakeCreationService::class);
    
    // Create a test image file
    $testImageContent = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=');
    
    // Save test image to temporary location
    Storage::fake('local');
    $tempPath = 'temp/test_image.jpg';
    Storage::disk('local')->put($tempPath, $testImageContent);
    
    // Create a mock UploadedFile
    $uploadedFile = new UploadedFile(
        Storage::disk('local')->path($tempPath),
        'test_quotation_image.jpg',
        'image/jpeg',
        null,
        true
    );
    
    echo "📤 Creating intake from image without contact info...\n";
    
    // Test image upload without any contact information
    $intake = $intakeService->createFromUploadedFile($uploadedFile, [
        'source' => 'test_upload',
        'priority' => 'normal',
        // Intentionally NO contact information provided
    ]);
    
    echo "✅ Intake created successfully!\n";
    echo "   - ID: {$intake->id}\n";
    echo "   - Status: {$intake->status}\n";
    echo "   - Source: {$intake->source}\n";
    
    // Check that it didn't get blocked by contact validation
    if ($intake->status === 'needs_contact') {
        echo "❌ FAIL: Intake was blocked for contact validation\n";
        echo "   This should NOT happen for images.\n";
        exit(1);
    } else {
        echo "✅ SUCCESS: Image intake bypassed contact validation\n";
        echo "   Status: '{$intake->status}' (should be 'pending')\n";
    }
    
    // Check file was stored
    $files = $intake->files;
    echo "\n📁 Files attached: " . $files->count() . "\n";
    
    if ($files->count() > 0) {
        $file = $files->first();
        echo "   - Filename: {$file->filename}\n";
        echo "   - MIME type: {$file->mime_type}\n";
        echo "   - Size: " . number_format($file->file_size) . " bytes\n";
        echo "   - Storage path: {$file->storage_path}\n";
        
        // Verify file exists
        if (Storage::disk($file->storage_disk)->exists($file->storage_path)) {
            echo "   ✅ File stored successfully\n";
        } else {
            echo "   ❌ File not found in storage\n";
        }
    }
    
    // Test base64 image upload as well
    echo "\n📤 Testing base64 image upload...\n";
    
    $base64Image = 'data:image/jpeg;base64,' . base64_encode($testImageContent);
    
    $base64Intake = $intakeService->createFromBase64Image($base64Image, 'screenshot_test.jpg', [
        'source' => 'test_screenshot',
        'priority' => 'normal',
    ]);
    
    echo "✅ Base64 intake created successfully!\n";
    echo "   - ID: {$base64Intake->id}\n";
    echo "   - Status: {$base64Intake->status}\n";
    
    if ($base64Intake->status === 'needs_contact') {
        echo "❌ FAIL: Base64 intake was blocked for contact validation\n";
        exit(1);
    } else {
        echo "✅ SUCCESS: Base64 image also bypassed contact validation\n";
    }
    
    echo "\n🔄 Processing status check...\n";
    
    // Wait a moment and check if processing started
    sleep(2);
    
    $intake->refresh();
    $base64Intake->refresh();
    
    echo "Image upload intake status: {$intake->status}\n";
    echo "Base64 upload intake status: {$base64Intake->status}\n";
    
    // Check configuration
    echo "\n⚙️  Configuration Check:\n";
    echo "   Skip contact validation types: " . implode(', ', config('intake.processing.skip_contact_validation', [])) . "\n";
    echo "   Require contact info: " . (config('intake.processing.require_contact_info', false) ? 'Yes' : 'No') . "\n";
    echo "   Image extraction enabled: " . (config('intake.processing.enable_image_extraction', true) ? 'Yes' : 'No') . "\n";
    
    echo "\n🎉 All tests passed! Image uploads work correctly without contact validation.\n";
    
} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . "\n";
    echo "   Line: " . $e->getLine() . "\n";
    echo "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

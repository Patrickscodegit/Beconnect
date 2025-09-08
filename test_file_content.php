<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->boot();

use App\Services\Export\Clients\RobawsApiClient;
use App\Models\IntakeFile;
use Illuminate\Support\Facades\Storage;

echo "=== Testing Enhanced File Content Retrieval ===\n\n";

$file = IntakeFile::first();
if (!$file) {
    echo "No IntakeFile found in database.\n";
    exit;
}

echo "File: {$file->filename}\n";
echo "Storage Path: {$file->storage_path}\n";
echo "Storage Disk: {$file->storage_disk}\n\n";

// Get the absolute path like RobawsExportService does
$absolutePath = Storage::disk($file->storage_disk)->path($file->storage_path);
echo "Absolute Path (from Storage::disk()->path()): {$absolutePath}\n\n";

// Create API client and test the getFileContent method
$apiClient = new RobawsApiClient();

try {
    // Use reflection to call the private method
    $reflection = new ReflectionClass($apiClient);
    $method = $reflection->getMethod('getFileContent');
    $method->setAccessible(true);
    
    $content = $method->invoke($apiClient, $absolutePath);
    
    echo "✅ SUCCESS: File content retrieved\n";
    echo "Content size: " . strlen($content) . " bytes\n";
    echo "First 100 chars: " . substr($content, 0, 100) . "...\n";
    
} catch (\Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    echo "Full error: " . $e . "\n";
}

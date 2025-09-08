<?php

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;
use App\Models\IntakeFile;

echo "🔍 Checking Production File Storage\n";
echo "=====================================\n\n";

// Check storage configuration
$diskConfig = config('filesystems.disks.documents');
echo "📁 Storage Configuration:\n";
echo "  Driver: " . ($diskConfig['driver'] ?? 'local') . "\n";
echo "  Root: " . ($diskConfig['root'] ?? 'N/A') . "\n";
if ($diskConfig['driver'] === 's3') {
    echo "  Bucket: " . ($diskConfig['bucket'] ?? 'N/A') . "\n";
    echo "  Endpoint: " . ($diskConfig['endpoint'] ?? 'AWS') . "\n";
}
echo "\n";

// Check recent files
$recentFiles = IntakeFile::orderBy('created_at', 'desc')->limit(5)->get();
echo "📄 Recent Files Check:\n";

foreach ($recentFiles as $file) {
    echo "\n  File ID: {$file->id}\n";
    echo "  Filename: {$file->filename}\n";
    echo "  Stored Path: {$file->storage_path}\n";
    echo "  Created: {$file->created_at}\n";
    
    $disk = Storage::disk('documents');
    
    // Try different path variations
    $normalizedPath = preg_replace('/^documents\//', '', $file->storage_path);
    $paths = [
        $file->storage_path,
        $normalizedPath,
        'documents/' . $normalizedPath,
        basename($file->storage_path)
    ];
    
    // Remove duplicates
    $paths = array_unique($paths);
    
    $found = false;
    foreach ($paths as $path) {
        if ($disk->exists($path)) {
            echo "  ✅ Found at: {$path}\n";
            try {
                echo "  Size: " . $disk->size($path) . " bytes\n";
                echo "  Last Modified: " . date('Y-m-d H:i:s', $disk->lastModified($path)) . "\n";
            } catch (Exception $e) {
                echo "  Size: Unable to get size - " . $e->getMessage() . "\n";
            }
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        echo "  ❌ File not found in storage!\n";
        echo "  Tried paths: " . implode(', ', $paths) . "\n";
    }
}

// Check for files with 'documents/' prefix in database
echo "\n🔍 Checking for files with 'documents/' prefix in database:\n";
$filesWithPrefix = IntakeFile::where('file_path', 'like', 'documents/%')->count();
echo "  Files with 'documents/' prefix: {$filesWithPrefix}\n";

if ($filesWithPrefix > 0) {
    echo "  ⚠️  These files need path migration!\n";
    echo "  Run: php artisan migrate --force\n";
} else {
    echo "  ✅ All file paths are properly normalized\n";
}

// List actual files in storage
echo "\n📂 Files in Storage (first 10):\n";
try {
    $files = Storage::disk('documents')->files();
    $count = 0;
    foreach ($files as $file) {
        echo "  - {$file}\n";
        $count++;
        if ($count >= 10) break;
    }

    if (count($files) > 10) {
        echo "  ... and " . (count($files) - 10) . " more files\n";
    }
    
    echo "\nTotal files in storage: " . count($files) . "\n";
} catch (Exception $e) {
    echo "  ❌ Error listing files: " . $e->getMessage() . "\n";
}

// Test file operations
echo "\n🧪 Testing File Operations:\n";
try {
    // Test write
    $testFile = 'health-check-' . time() . '.txt';
    $testContent = 'File operation test - ' . date('Y-m-d H:i:s');
    
    Storage::disk('documents')->put($testFile, $testContent);
    echo "  ✅ Write test: SUCCESS\n";
    
    // Test read
    $readContent = Storage::disk('documents')->get($testFile);
    if ($readContent === $testContent) {
        echo "  ✅ Read test: SUCCESS\n";
    } else {
        echo "  ❌ Read test: FAILED - Content mismatch\n";
    }
    
    // Test delete
    Storage::disk('documents')->delete($testFile);
    echo "  ✅ Delete test: SUCCESS\n";
    
} catch (Exception $e) {
    echo "  ❌ File operation test failed: " . $e->getMessage() . "\n";
}

// Environment info
echo "\n🌍 Environment Information:\n";
echo "  Environment: " . app()->environment() . "\n";
echo "  Laravel Version: " . app()->version() . "\n";
echo "  PHP Version: " . phpversion() . "\n";
echo "  Storage Path: " . storage_path() . "\n";

// Disk space check (for local storage)
if ($diskConfig['driver'] === 'local') {
    echo "\n💾 Disk Space Check:\n";
    $storageRoot = $diskConfig['root'] ?? storage_path('app/documents');
    if (is_dir($storageRoot)) {
        $bytes = disk_free_space($storageRoot);
        $gb = round($bytes / (1024 * 1024 * 1024), 2);
        echo "  Free space: {$gb} GB\n";
    } else {
        echo "  ❌ Storage directory does not exist: {$storageRoot}\n";
    }
}

echo "\n✅ Check complete!\n";
echo "=====================================\n";

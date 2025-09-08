<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Storage;

// Load Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->boot();

// Check what storeAs actually returns
echo "=== Laravel Storage storeAs Analysis ===\n\n";

// Check disk config
$documentsConfig = config('filesystems.disks.documents');
echo "Documents disk config:\n";
print_r($documentsConfig);

echo "\n";

// Check what paths look like
$testPath = 'test-file.txt';

echo "Testing Storage operations:\n";
echo "Direct path: " . $testPath . "\n";
echo "Storage::disk('documents')->path('$testPath'): " . Storage::disk('documents')->path($testPath) . "\n";

// Check if we can find any existing files
echo "\n=== Existing IntakeFile Records ===\n";
$intakeFiles = \App\Models\IntakeFile::take(5)->get(['id', 'filename', 'storage_path', 'storage_disk']);
foreach ($intakeFiles as $file) {
    echo "ID: {$file->id}, filename: {$file->filename}, storage_path: '{$file->storage_path}', disk: {$file->storage_disk}\n";
    
    // Test if the file exists using the current path
    $exists = Storage::disk($file->storage_disk)->exists($file->storage_path);
    echo "  Exists check with current path: " . ($exists ? 'YES' : 'NO') . "\n";
    
    // Test without documents prefix if it exists
    if (str_starts_with($file->storage_path, 'documents/')) {
        $withoutPrefix = substr($file->storage_path, 10); // remove 'documents/'
        $existsWithoutPrefix = Storage::disk($file->storage_disk)->exists($withoutPrefix);
        echo "  Exists check WITHOUT documents/ prefix ('$withoutPrefix'): " . ($existsWithoutPrefix ? 'YES' : 'NO') . "\n";
    }
    
    echo "\n";
}

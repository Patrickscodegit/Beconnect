<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Intake;
use App\Models\IntakeFile;

echo "=== Analyzing Uploaded Files ===\n";

$intakes = Intake::with('files')->whereIn('id', [2,3,4,5,6,7,8])->orderBy('id')->get();

foreach ($intakes as $intake) {
    echo "\nIntake {$intake->id}:\n";
    echo "  Status: {$intake->status}\n";
    echo "  Customer: " . ($intake->customer_name ?: 'None') . "\n";
    echo "  Email: " . ($intake->contact_email ?: 'None') . "\n";
    
    $files = $intake->files;
    echo "  Files ({$files->count()}):\n";
    
    foreach ($files as $file) {
        echo "    - {$file->filename} ({$file->mime_type}) - {$file->file_size} bytes\n";
        echo "      Path: {$file->storage_path}\n";
        echo "      Hash: " . substr(md5($file->storage_path . $file->file_size), 0, 8) . "\n";
    }
    
    if ($files->isEmpty()) {
        echo "    No files uploaded\n";
    }
}

// Check for duplicate filenames or file sizes
echo "\n=== Duplicate File Analysis ===\n";
$allFiles = IntakeFile::whereHas('intake', function($q) {
    $q->whereIn('id', [2,3,4,5,6,7,8]);
})->get(['filename', 'file_size', 'intake_id', 'storage_path']);

$fileHashes = [];
foreach ($allFiles as $file) {
    $hash = md5($file->filename . $file->file_size);
    if (!isset($fileHashes[$hash])) {
        $fileHashes[$hash] = [];
    }
    $fileHashes[$hash][] = "Intake {$file->intake_id}: {$file->filename}";
}

foreach ($fileHashes as $hash => $files) {
    if (count($files) > 1) {
        echo "Duplicate files (hash: $hash):\n";
        foreach ($files as $file) {
            echo "  - $file\n";
        }
    }
}

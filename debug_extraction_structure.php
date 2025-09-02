<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\IntakeFile;
use App\Services\ExtractionService;
use Illuminate\Support\Facades\Log;

echo "=== Debug Extraction Result Structure ===\n\n";

try {
    // Get the IntakeFile we created
    $file = \App\Models\IntakeFile::find(1);
    
    if (!$file) {
        echo "❌ IntakeFile not found\n";
        exit(1);
    }
    
    echo "📄 Testing extraction on file:\n";
    echo "   ID: {$file->id}\n";
    echo "   Filename: {$file->filename}\n";
    echo "   MIME: {$file->mime_type}\n\n";
    
    // Extract data directly
    $extractionService = app(\App\Services\ExtractionService::class);
    $result = $extractionService->extractFromFile($file);
    
    echo "🔍 Raw extraction result structure:\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
    
    // Check where contact data is
    if (isset($result['contact'])) {
        echo "✅ Contact data found at root level:\n";
        echo "   " . json_encode($result['contact'], JSON_PRETTY_PRINT) . "\n\n";
    }
    
    if (isset($result['raw_data']['contact'])) {
        echo "✅ Contact data found in raw_data:\n";
        echo "   " . json_encode($result['raw_data']['contact'], JSON_PRETTY_PRINT) . "\n\n";
    }
    
    // Check all top-level keys
    echo "📋 Top-level keys in extraction result:\n";
    foreach (array_keys($result) as $key) {
        echo "   - {$key}\n";
    }

} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Debug Complete ===\n";

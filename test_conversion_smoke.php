<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;

echo "🧪 Testing DocumentConversion artifact generation...\n\n";

$doc = Document::latest()->first();
if (!$doc) {
    echo "❌ No documents found\n";
    exit;
}

echo "📄 Testing with document: {$doc->filename}\n";

try {
    $conv = app(\App\Services\DocumentConversion::class);
    $art  = $conv->ensureUploadArtifact($doc);

    echo "✅ Artifact generated successfully:\n";
    echo "   Path: " . ($art['path'] ?? 'NULL') . "\n";
    echo "   MIME: " . ($art['mime'] ?? 'NULL') . "\n";
    echo "   Size: " . ($art['size'] ?? 'NULL') . " bytes\n";
    echo "   Filename: " . ($art['filename'] ?? 'NULL') . "\n";
    echo "   Source: " . ($art['source'] ?? 'NULL') . "\n";

    if (file_exists($art['path'])) {
        echo "   SHA256: " . hash_file('sha256', $art['path']) . "\n";
    } else {
        echo "   ❌ File does not exist: " . $art['path'] . "\n";
    }

} catch (\Throwable $e) {
    echo "❌ Conversion failed: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

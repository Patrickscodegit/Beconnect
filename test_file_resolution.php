<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧪 Testing File Resolution\n";
echo "============================================================\n";

try {
    $doc = \App\Models\Document::first();
    if (!$doc) {
        echo "❌ No documents found\n";
        exit;
    }
    
    echo "📄 Document ID: {$doc->id}\n";
    echo "📁 Stored file_path: {$doc->file_path}\n";
    echo "💾 Storage disk: {$doc->storage_disk}\n";
    
    // Test the new resolver
    $meta = \App\Services\Robaws\DocumentStreamResolver::openDocumentStream($doc, \Illuminate\Support\Facades\Log::getLogger());
    
    echo "\n✅ File Resolution SUCCESS!\n";
    echo "   Disk: {$meta['disk_name']}\n";
    echo "   Resolved path: {$meta['path']}\n";
    echo "   Filename: {$meta['filename']}\n";
    echo "   MIME: {$meta['mime']}\n";
    echo "   Size: " . ($meta['size'] ? number_format($meta['size']) . ' bytes' : 'unknown') . "\n";
    
    // Test SHA computation
    $sha = \App\Services\Robaws\DocumentStreamResolver::computeSha256FromDisk($meta['disk'], $meta['path']);
    echo "   SHA256: {$sha}\n";
    
    // Clean up stream
    if (is_resource($meta['stream'])) {
        fclose($meta['stream']);
    }
    
    echo "\n🎉 File resolution working perfectly!\n";
    
} catch (\Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

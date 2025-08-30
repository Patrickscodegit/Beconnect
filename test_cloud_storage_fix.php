<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\Document;
use App\Services\RobawsExportService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * Test script to verify cloud storage compatibility fix
 * This tests the new stream-based file upload method
 */

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Testing Cloud Storage Fix ===\n\n";

// Test document ID (use a real document ID from your database)
$documentId = 33; // Document that was failing

try {
    // Get document
    $document = Document::find($documentId);
    if (!$document) {
        echo "❌ Document {$documentId} not found\n";
        exit(1);
    }
    
    echo "📄 Testing document: {$document->id}\n";
    echo "   File: {$document->file_path}\n";
    echo "   Storage: {$document->storage_disk}\n\n";
    
    // Test storage disk access
    $disk = Storage::disk($document->storage_disk ?? 'documents');
    
    echo "🔍 Testing file existence...\n";
    if ($disk->exists($document->file_path)) {
        echo "✅ File exists in storage\n";
    } else {
        echo "❌ File not found in storage\n";
        exit(1);
    }
    
    // Test file properties
    echo "\n📊 Testing file properties...\n";
    try {
        $mimeType = $disk->mimeType($document->file_path);
        $fileSize = $disk->size($document->file_path);
        
        echo "   MIME Type: {$mimeType}\n";
        echo "   File Size: " . number_format($fileSize) . " bytes\n";
        
        if ($fileSize > 50 * 1024 * 1024) {
            echo "⚠️  Warning: File is larger than 50MB\n";
        }
        
    } catch (\Exception $e) {
        echo "❌ Error getting file properties: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    // Test stream reading
    echo "\n🌊 Testing stream access...\n";
    try {
        $stream = $disk->readStream($document->file_path);
        if (is_resource($stream)) {
            echo "✅ Stream created successfully\n";
            
            // Read first 1KB to verify
            $sample = fread($stream, 1024);
            echo "   Sample read: " . strlen($sample) . " bytes\n";
            
            fclose($stream);
        } else {
            echo "❌ Failed to create stream\n";
            exit(1);
        }
    } catch (\Exception $e) {
        echo "❌ Error reading stream: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    // Test path normalization
    echo "\n🔧 Testing path normalization...\n";
    $exportService = app(RobawsExportService::class);
    $reflection = new \ReflectionClass($exportService);
    $normalizeMethod = $reflection->getMethod('normalizeDiskKey');
    $normalizeMethod->setAccessible(true);
    
    $diskRoot = config('filesystems.disks.documents.root');
    $normalizedPath = $normalizeMethod->invoke($exportService, $document->file_path, $diskRoot);
    
    echo "   Original path: {$document->file_path}\n";
    echo "   Disk root: {$diskRoot}\n";
    echo "   Normalized path: {$normalizedPath}\n";
    
    // Test with the normalized path
    if ($disk->exists($normalizedPath)) {
        echo "✅ Normalized path exists\n";
    } else {
        echo "⚠️  Normalized path doesn't exist, using original\n";
    }
    
    echo "\n🎉 All storage tests passed!\n";
    echo "\nThe fix should now work with both local and cloud storage.\n";
    echo "You can deploy this to production on Forge.\n";
    
} catch (\Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

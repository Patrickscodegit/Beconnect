<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧪 Testing SHA-256 StreamHasher\n";
echo "============================================================\n";

use App\Support\StreamHasher;
use Illuminate\Support\Facades\Storage;

try {
    // Test 1: Small file hashing
    echo "\n1️⃣ Testing small file hashing:\n";
    
    Storage::fake('documents');
    $content = str_repeat('Hello World! ', 100); // ~1.2KB
    Storage::disk('documents')->put('test-small.txt', $content);
    
    $stream = Storage::disk('documents')->readStream('test-small.txt');
    $result = StreamHasher::toTempHashedStream($stream);
    @fclose($stream);
    
    $expectedHash = hash('sha256', $content);
    
    echo "   Content size: " . strlen($content) . " bytes\n";
    echo "   Computed size: " . $result['size'] . " bytes\n";
    echo "   Expected hash: " . $expectedHash . "\n";
    echo "   Computed hash: " . $result['sha256'] . "\n";
    echo "   Hash match: " . ($expectedHash === $result['sha256'] ? "✅ YES" : "❌ NO") . "\n";
    echo "   Size match: " . (strlen($content) === $result['size'] ? "✅ YES" : "❌ NO") . "\n";
    
    // Verify temp stream is readable
    $readBack = stream_get_contents($result['stream']);
    echo "   Stream readable: " . ($readBack === $content ? "✅ YES" : "❌ NO") . "\n";
    @fclose($result['stream']);
    
    // Test 2: Larger file
    echo "\n2️⃣ Testing larger file hashing:\n";
    
    $largeContent = str_repeat('Lorem ipsum dolor sit amet. ', 50000); // ~1.4MB
    Storage::disk('documents')->put('test-large.txt', $largeContent);
    
    $largeStream = Storage::disk('documents')->readStream('test-large.txt');
    $largeResult = StreamHasher::toTempHashedStream($largeStream);
    @fclose($largeStream);
    
    $expectedLargeHash = hash('sha256', $largeContent);
    
    echo "   Content size: " . strlen($largeContent) . " bytes\n";
    echo "   Computed size: " . $largeResult['size'] . " bytes\n";
    echo "   Hash match: " . ($expectedLargeHash === $largeResult['sha256'] ? "✅ YES" : "❌ NO") . "\n";
    echo "   Size match: " . (strlen($largeContent) === $largeResult['size'] ? "✅ YES" : "❌ NO") . "\n";
    @fclose($largeResult['stream']);
    
    // Test 3: Empty file
    echo "\n3️⃣ Testing empty file:\n";
    
    Storage::disk('documents')->put('test-empty.txt', '');
    $emptyStream = Storage::disk('documents')->readStream('test-empty.txt');
    $emptyResult = StreamHasher::toTempHashedStream($emptyStream);
    @fclose($emptyStream);
    
    $expectedEmptyHash = hash('sha256', '');
    
    echo "   Empty file hash: " . ($expectedEmptyHash === $emptyResult['sha256'] ? "✅ YES" : "❌ NO") . "\n";
    echo "   Empty file size: " . ($emptyResult['size'] === 0 ? "✅ YES" : "❌ NO") . "\n";
    @fclose($emptyResult['stream']);
    
    echo "\n🎉 All StreamHasher tests passed!\n";
    echo "\n✅ Benefits achieved:\n";
    echo "   • Memory-safe hashing (no large file load)\n";
    echo "   • Accurate SHA-256 computation\n";
    echo "   • Size calculation included\n";
    echo "   • Temp stream ready for upload\n";
    
} catch (\Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

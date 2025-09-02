<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Export\Clients\RobawsApiClient;

echo "=== Testing Fixed File Upload to Quotation O251114 ===" . PHP_EOL;

$apiClient = new RobawsApiClient();

// Step 1: Find the internal numeric ID for quotation O251114
echo "Step 1: Finding internal ID for quotation O251114..." . PHP_EOL;

$offer = $apiClient->getOfferByNumber('O251114');

if (!$offer) {
    echo "❌ Could not find quotation O251114" . PHP_EOL;
    exit(1);
}

$offerId = $offer['id'];
$title = $offer['title'] ?? 'N/A';

echo "✅ Found quotation:" . PHP_EOL;
echo "  - Human ID: O251114" . PHP_EOL;
echo "  - Internal ID: {$offerId}" . PHP_EOL;
echo "  - Title: {$title}" . PHP_EOL;

// Step 2: Create a test file to upload
echo PHP_EOL . "Step 2: Creating test file..." . PHP_EOL;

$testFile = 'test_upload_to_offer.txt';
$testContent = "Test document for quotation O251114\n";
$testContent .= "Upload date: " . date('Y-m-d H:i:s') . "\n";
$testContent .= "Customer: Badr Algothami\n";
$testContent .= "Vehicle: BMW Serie 7\n";
$testContent .= "Route: Brussels → Jeddah\n";

file_put_contents($testFile, $testContent);
$fileSize = filesize($testFile);

echo "✅ Created test file: {$testFile} ({$fileSize} bytes)" . PHP_EOL;

// Step 3: Upload the file to the quotation
echo PHP_EOL . "Step 3: Uploading file to quotation..." . PHP_EOL;

try {
    $result = $apiClient->attachFileToOffer($offerId, $testFile);
    
    echo "✅ File upload successful!" . PHP_EOL;
    echo "  - Document ID: " . ($result['id'] ?? 'N/A') . PHP_EOL;
    echo "  - Filename: " . ($result['filename'] ?? 'N/A') . PHP_EOL;
    echo "  - Attached to offer: " . ($result['attached_to_offer'] ?? $offerId) . PHP_EOL;
    
    echo PHP_EOL . "🎉 SUCCESS: File should now be visible in Robaws UI under Documents tab!" . PHP_EOL;
    
} catch (Exception $e) {
    echo "❌ Upload failed: " . $e->getMessage() . PHP_EOL;
    
    // Additional debugging
    echo PHP_EOL . "Debug information:" . PHP_EOL;
    echo "- Offer ID: {$offerId}" . PHP_EOL;
    echo "- File path: " . realpath($testFile) . PHP_EOL;
    echo "- File exists: " . (file_exists($testFile) ? 'Yes' : 'No') . PHP_EOL;
    echo "- File size: {$fileSize} bytes" . PHP_EOL;
} finally {
    // Clean up test file
    if (file_exists($testFile)) {
        unlink($testFile);
        echo PHP_EOL . "Cleaned up test file." . PHP_EOL;
    }
}

echo PHP_EOL . "=== File Upload Test Complete ===" . PHP_EOL;

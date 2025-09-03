<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->boot();

// Simple file upload test
echo "=== FILE UPLOAD TO ROBAWS TEST ===\n";

$testContent = 'Test document for Carhanco offer - Alfa Romeo Giulietta shipping quote';
$testFile = storage_path('app/carhanco_test.txt');
file_put_contents($testContent, $testFile);

echo "Created test file: " . basename($testFile) . " (" . strlen($testContent) . " bytes)\n";

$carhancoOfferId = 11637;
$exportService = app(App\Services\Robaws\RobawsExportService::class);
$result = $exportService->uploadDocumentToOffer($carhancoOfferId, $testFile);

echo "\nUpload to offer $carhancoOfferId:\n";
echo "Status: " . ($result['status'] ?? 'unknown') . "\n";
if (isset($result['error'])) {
    echo "Error: " . $result['error'] . "\n";
}
if (isset($result['document'])) {
    echo "Document ID: " . ($result['document']['id'] ?? 'none') . "\n";
    echo "SHA256: " . ($result['document']['sha256'] ?? 'none') . "\n";
}

if (file_exists($testFile)) {
    unlink($testFile);
}
echo "\n✅ File upload test completed!\n";

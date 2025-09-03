<?php

// Test file upload functionality to Robaws
use App\Services\Robaws\RobawsExportService;
use App\Services\RobawsClient;

echo "=== TESTING ROBAWS FILE UPLOAD FUNCTIONALITY ===\n\n";

// Check if we can access the services
try {
    $exportService = app(RobawsExportService::class);
    echo "✅ RobawsExportService accessible\n";
} catch (\Exception $e) {
    echo "❌ RobawsExportService error: " . $e->getMessage() . "\n";
}

try {
    $robawsClient = app(RobawsClient::class);
    echo "✅ RobawsClient accessible\n";
} catch (\Exception $e) {
    echo "❌ RobawsClient error: " . $e->getMessage() . "\n";
}

// Check available upload methods
echo "\n=== UPLOAD METHODS AVAILABLE ===\n";

$reflection = new ReflectionClass(RobawsExportService::class);
$methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

foreach ($methods as $method) {
    if (strpos(strtolower($method->getName()), 'upload') !== false) {
        echo "📄 " . $method->getName() . "()\n";
    }
}

// Check RobawsClient upload methods
$clientReflection = new ReflectionClass(RobawsClient::class);
$clientMethods = $clientReflection->getMethods(ReflectionMethod::IS_PUBLIC);

echo "\n=== ROBAWS CLIENT UPLOAD METHODS ===\n";
foreach ($clientMethods as $method) {
    if (strpos(strtolower($method->getName()), 'upload') !== false || 
        strpos(strtolower($method->getName()), 'document') !== false ||
        strpos(strtolower($method->getName()), 'file') !== false ||
        strpos(strtolower($method->getName()), 'attach') !== false) {
        echo "🔗 " . $method->getName() . "()\n";
    }
}

// Test creating a simple test file and upload
echo "\n=== TESTING FILE UPLOAD FLOW ===\n";

// Create a test PDF file
$testContent = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\nxref\n0 4\n0000000000 65535 f \n0000000010 00000 n \n0000000053 00000 n \n0000000103 00000 n \ntrailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n149\n%%EOF";
$testFile = storage_path('app/test_upload.pdf');
file_put_contents($testFile, $testContent);

echo "📁 Created test file: " . basename($testFile) . " (" . strlen($testContent) . " bytes)\n";

// Test upload to a test offer ID (we'll use 11637 from earlier)
$testOfferId = 11637;

try {
    echo "\n🚀 Testing upload to offer $testOfferId...\n";
    
    $result = $exportService->uploadDocumentToOffer($testOfferId, $testFile);
    
    echo "Upload result:\n";
    echo "Status: " . ($result['status'] ?? 'unknown') . "\n";
    if (isset($result['error'])) {
        echo "Error: " . $result['error'] . "\n";
    }
    if (isset($result['document'])) {
        echo "Document ID: " . ($result['document']['id'] ?? 'none') . "\n";
        echo "SHA256: " . ($result['document']['sha256'] ?? 'none') . "\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Upload test failed: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

// Clean up
if (file_exists($testFile)) {
    unlink($testFile);
    echo "\n🗑️ Test file cleaned up\n";
}

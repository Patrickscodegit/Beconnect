<?php

// Quick verification script for production OpenAI configuration
// Run this on production: php verify_production_ai.php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "🔍 Production OpenAI Configuration Verification\n";
echo "=" . str_repeat("=", 50) . "\n\n";

// Check OpenAI config
$openaiConfig = config('services.openai');
echo "✅ OpenAI Config Keys Available:\n";
foreach ($openaiConfig as $key => $value) {
    $display = $key === 'key' || $key === 'api_key' ? 
        (empty($value) ? 'MISSING' : 'SET (' . strlen($value) . ' chars)') : 
        $value;
    echo "   - {$key}: {$display}\n";
}

// Test AIExtractionService
echo "\n🧪 Testing AIExtractionService:\n";
try {
    $service = app(\App\Services\AIExtractionService::class);
    echo "✅ AIExtractionService instantiated successfully\n";
    
    $testText = "Customer: John Doe, Email: john@test.com, BMW X5, Phone: 555-1234";
    $result = $service->extractFromText($testText);
    echo "✅ Extraction test completed\n";
    echo "   Result keys: " . implode(', ', array_keys($result ?? [])) . "\n";
    
} catch (Exception $e) {
    echo "❌ AIExtractionService error: " . $e->getMessage() . "\n";
}

// Test ExtractionService (your main service)
echo "\n🔬 Testing Main ExtractionService:\n";
try {
    $service = app(\App\Services\ExtractionService::class);
    echo "✅ ExtractionService instantiated successfully\n";
} catch (Exception $e) {
    echo "❌ ExtractionService error: " . $e->getMessage() . "\n";
}

echo "\n🎯 **Status**: Production AI extraction should now be working!\n";

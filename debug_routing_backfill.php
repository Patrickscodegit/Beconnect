<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;

echo "🔍 Debugging routing backfill logic...\n\n";

// Test the IATA code extraction logic directly
$service = app(EnhancedRobawsIntegrationService::class);

// Use reflection to access private methods for testing
$reflection = new ReflectionClass($service);
$extractIataCodesMethod = $reflection->getMethod('extractIataCodes');
$extractIataCodesMethod->setAccessible(true);

$codeToCityMethod = $reflection->getMethod('codeToCity');
$codeToCityMethod->setAccessible(true);

$cityToPortMethod = $reflection->getMethod('cityToPort');
$cityToPortMethod->setAccessible(true);

$backfillMethod = $reflection->getMethod('backfillRoutingFromText');
$backfillMethod->setAccessible(true);

echo "1️⃣ Testing IATA code extraction...\n";
$testTexts = [
    'EXP RORO - BRU - JED - BMW Série 7',
    'BRU to JED transport',
    'Brussels ANR Jeddah',
    'Transport from BRU port to JED destination'
];

foreach ($testTexts as $text) {
    $codes = $extractIataCodesMethod->invoke($service, $text);
    echo "   Text: '{$text}'\n";
    echo "   Extracted codes: [" . implode(', ', $codes) . "]\n";
    
    foreach ($codes as $code) {
        $city = $codeToCityMethod->invoke($service, $code);
        $port = $cityToPortMethod->invoke($service, $city ?? $code);
        echo "     {$code} → {$city} → {$port}\n";
    }
    echo "\n";
}

echo "2️⃣ Testing backfill logic directly...\n";
$testData = [
    'customer_reference' => 'EXP RORO - BRU - JED - BMW Série 7',
    'por' => '',
    'pol' => '',
    'pod' => ''
];

$extractedData = [
    'customer_reference' => 'EXP RORO - BRU - JED - BMW Série 7'
];

echo "   Input data:\n";
echo "     customer_reference: " . $testData['customer_reference'] . "\n";
echo "     por: '" . $testData['por'] . "'\n";
echo "     pod: '" . $testData['pod'] . "'\n\n";

$result = $backfillMethod->invoke($service, $testData, $extractedData);

echo "   Result after backfill:\n";
echo "     customer_reference: " . ($result['customer_reference'] ?? 'NULL') . "\n";
echo "     por: " . ($result['por'] ?? 'NULL') . "\n";
echo "     pol: " . ($result['pol'] ?? 'NULL') . "\n";
echo "     pod: " . ($result['pod'] ?? 'NULL') . "\n";

if (!empty($result['por']) && !empty($result['pod'])) {
    echo "\n✅ Backfill logic works correctly!\n";
} else {
    echo "\n❌ Backfill logic failed - investigating further...\n";
}

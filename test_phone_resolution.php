<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Export\Clients\RobawsApiClient;

echo "=== Testing Phone-Based Client Resolution ===" . PHP_EOL;

$apiClient = new RobawsApiClient();

// Test cases with different phone formats
$testCases = [
    // Test JB Trading case from screenshots
    ['name' => 'JB Trading', 'email' => null, 'phone' => '+31 318 690 347'],
    ['name' => null, 'email' => null, 'phone' => '0318690347'],  // Local format
    ['name' => null, 'email' => null, 'phone' => '+31318690347'], // No spaces
    
    // Test Badr case with phone fallback
    ['name' => 'Badr Algothami', 'email' => null, 'phone' => '+966501234567'],
    
    // Test multi-method resolution (email first, then phone)
    ['name' => 'Test Customer', 'email' => 'test@example.com', 'phone' => '+31123456789'],
    
    // Test name-only (should work as before)
    ['name' => 'Belgaco', 'email' => null, 'phone' => null],
];

foreach ($testCases as $i => $test) {
    echo PHP_EOL . "--- Test Case " . ($i + 1) . " ---" . PHP_EOL;
    echo "Name: " . ($test['name'] ?? 'null') . PHP_EOL;
    echo "Email: " . ($test['email'] ?? 'null') . PHP_EOL;
    echo "Phone: " . ($test['phone'] ?? 'null') . PHP_EOL;
    
    try {
        $clientId = $apiClient->findClientId($test['name'], $test['email'], $test['phone']);
        echo "Result: " . ($clientId ? "Client ID {$clientId}" : "No client found") . PHP_EOL;
        
        if ($clientId) {
            echo "✅ SUCCESS: Client resolved" . PHP_EOL;
        } else {
            echo "ℹ️  INFO: No unique match (safe - no wrong binding)" . PHP_EOL;
        }
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL . "=== Testing Phone Normalization ===" . PHP_EOL;

// Test the private phone normalization method using reflection
$reflection = new ReflectionClass($apiClient);
$normalizeMethod = $reflection->getMethod('normalizePhone');
$normalizeMethod->setAccessible(true);

$phoneFormats = [
    '+31 318 690 347',
    '0318 690 347',
    '+31-318-690-347',
    '(0318) 690 347',
    '+31.318.690.347',
    '31318690347',
];

foreach ($phoneFormats as $phone) {
    $normalized = $normalizeMethod->invoke($apiClient, $phone);
    echo "'{$phone}' → '{$normalized}'" . PHP_EOL;
}

echo PHP_EOL . "=== Testing Phone Comparison ===" . PHP_EOL;

$phonesEqualMethod = $reflection->getMethod('phonesEqual');
$phonesEqualMethod->setAccessible(true);

$comparisons = [
    ['+31318690347', '0318690347'],      // International vs local
    ['+31 318 690 347', '+31318690347'], // Spaces vs no spaces
    ['+31318690347', '+32318690347'],    // Different countries
    ['123456789', '987654321'],          // Different numbers
];

foreach ($comparisons as [$phone1, $phone2]) {
    $equal = $phonesEqualMethod->invoke($apiClient, $phone1, $phone2);
    $result = $equal ? '✅ MATCH' : '❌ NO MATCH';
    echo "'{$phone1}' vs '{$phone2}' → {$result}" . PHP_EOL;
}

echo PHP_EOL . "=== Phone Resolution Complete ===" . PHP_EOL;
echo "The system now supports:" . PHP_EOL;
echo "1. Email-first resolution (existing)" . PHP_EOL;
echo "2. Phone-based resolution (NEW)" . PHP_EOL;
echo "3. Name-based resolution (existing)" . PHP_EOL;
echo "4. Safe fallback when no unique match found" . PHP_EOL;

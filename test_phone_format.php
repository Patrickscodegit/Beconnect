<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Export\Clients\RobawsApiClient;

echo "=== Testing Phone Number Format Standardization ===\n";

// Create an instance with reflection to access the protected method
$mapper = new RobawsMapper(new RobawsApiClient());
$reflection = new ReflectionClass($mapper);
$method = $reflection->getMethod('normalizePhoneNumber');
$method->setAccessible(true);

$testNumbers = [
    '+32034358657' => '+32(0)34358657',           // International format
    '+32 034 358 657' => '+32(0)34358657',       // International with spaces
    '+32 (0)3 435 86 57' => '+32(0)34358657',    // Mixed format
    '034358657' => '+32(0)34358657',             // Local format (short)
    '0034358657' => '+32(0)34358657',            // Local format with extra zero
    '003 435 86 57' => '+32(0)34358657',         // Local with spaces
    '+32476720216' => '+32(0)476720216',         // Mobile number
    '0476720216' => '+32(0)476720216',           // Local mobile
    '+1-555-123-4567' => '+1-555-123-4567',      // Non-Belgian (should remain unchanged)
    '' => null,                                  // Empty
    null => null                                 // Null
];

echo "Testing phone number normalization:\n\n";

foreach ($testNumbers as $input => $expected) {
    $result = $method->invoke($mapper, $input);
    $status = $result === $expected ? '✅' : '❌';
    $inputDisplay = $input === null ? 'null' : "'$input'";
    $expectedDisplay = $expected === null ? 'null' : "'$expected'";
    $resultDisplay = $result === null ? 'null' : "'$result'";
    
    echo sprintf("%s %-25s -> %-20s (expected: %s)\n", 
        $status, 
        $inputDisplay,
        $resultDisplay,
        $expectedDisplay
    );
}

echo "\n=== Test Complete ===\n";

// Count results
$passed = 0;
$failed = 0;

foreach ($testNumbers as $input => $expected) {
    $result = $method->invoke($mapper, $input);
    if ($result === $expected) {
        $passed++;
    } else {
        $failed++;
    }
}

echo "Tests passed: $passed\n";
echo "Tests failed: $failed\n";

if ($failed === 0) {
    echo "\n🎉 All tests passed! Phone numbers will now be formatted as +32(0)XXXXXXXX\n";
    echo "✅ Nancy Deckers and other contacts will show consistent phone format\n";
    echo "✅ Both landline and mobile numbers will be standardized\n";
} else {
    echo "\n❌ Some tests failed. Please check the implementation.\n";
}

echo "\n=== Format Examples ===\n";
echo "Before: '+32 (0)3 435 86 57'\n";
echo "After:  '+32(0)34358657'\n";
echo "\nBefore: '0476 72 02 16'\n";
echo "After:  '+32(0)476720216'\n";

<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Export\Mappers\RobawsMapper;

echo "=== Testing Belgian Phone Number Normalization ===\n";

// Create a test instance to access the protected method
$mapper = new class extends RobawsMapper {
    public function testNormalizePhoneNumber($phone) {
        return $this->normalizePhoneNumber($phone);
    }
};

$testCases = [
    // Valid Belgian numbers - should be normalized to +32(0)XXXXXXXX format
    '+32 (0)3 435 86 57' => '+32(0)34358657',
    '+32(0)34358657' => '+32(0)34358657',
    '+3234358657' => '+32(0)34358657', 
    '+32034358657' => '+32(0)34358657',
    '003234358657' => '+32(0)34358657',    // International format for Belgium  
    '034358657' => '+32(0)34358657',       // National Belgian format
    '0476720216' => '+32(0)476720216',     // Mobile number
    
    // Non-Belgian numbers - should remain unchanged
    '0034358657' => '0034358657',          // Spanish number (00+34) - leave as is
    '+34358657' => '+34358657',            // Spanish number - leave as is  
    '+33123456789' => '+33123456789',      // French number - leave as is
    '+44123456789' => '+44123456789',      // UK number - leave as is
    
    // Edge cases
    '' => '',                              // Empty string -> null, but we'll show empty
    '123' => '123',                        // Too short - leave as is
];

echo "Testing phone number normalization:\n";
echo sprintf("%-25s -> %-25s (Expected: %s)\n", "Input", "Result", "Expected");
echo str_repeat("-", 80) . "\n";

$passed = 0;
$failed = 0;

foreach ($testCases as $input => $expected) {
    $result = $mapper->testNormalizePhoneNumber($input) ?? '';
    $status = $result === $expected ? '✅' : '❌';
    
    echo sprintf("%-25s -> %-25s %s\n", 
        $input === '' ? '(empty)' : $input, 
        $result === '' ? '(empty)' : $result,
        $status
    );
    
    if ($result === $expected) {
        $passed++;
    } else {
        $failed++;
        echo sprintf("   Expected: %s\n", $expected === '' ? '(empty)' : $expected);
    }
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "Tests passed: {$passed}\n";
echo "Tests failed: {$failed}\n";

if ($failed === 0) {
    echo "\n🎉 All tests passed! Phone normalization working correctly.\n";
    echo "✅ Belgian numbers normalized to +32(0)XXXXXXXX format\n";
    echo "✅ Non-Belgian numbers (Spanish, French, etc.) left unchanged\n";
} else {
    echo "\n❌ Some tests failed. Please check the normalization logic.\n";
}

echo "\n=== Testing with Nancy Deckers example ===\n";
$nancyPhone = '+32 (0)3 435 86 57';
$nancyMobile = '+32 (0)476 72 02 16';

echo "Nancy's phone: '{$nancyPhone}' -> '" . ($mapper->testNormalizePhoneNumber($nancyPhone) ?? '') . "'\n";
echo "Nancy's mobile: '{$nancyMobile}' -> '" . ($mapper->testNormalizePhoneNumber($nancyMobile) ?? '') . "'\n";

echo "\n✅ These should now display consistently as +32(0)34358657 format in Robaws!\n";

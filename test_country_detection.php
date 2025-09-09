<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== COUNTRY DETECTION TEST ===\n";

// Test with the French phone number from intake 1
$testData = [
    'phone' => '+33 7 58 20 01 22',
    'email' => 'kay.importexport1@gmail.com',
    'company' => 'Faso Groupage',
    'name' => 'Alphonse YAMEOGO'
];

echo "Test data:\n";
print_r($testData);

// Test phone number detection
function detectCountryFromPhone($phone) {
    $cleanPhone = preg_replace('/[^\d+]/', '', $phone);
    echo "\nPhone analysis:\n";
    echo "Original: $phone\n";
    echo "Cleaned: $cleanPhone\n";
    
    if (str_starts_with($cleanPhone, '+33')) {
        return ['country' => 'France', 'country_code' => 'FR'];
    }
    
    return null;
}

$result = detectCountryFromPhone($testData['phone']);
if ($result) {
    echo "\n✅ Country detected from phone number!\n";
    echo "Country: " . $result['country'] . "\n";
    echo "Country Code: " . $result['country_code'] . "\n";
} else {
    echo "\n❌ No country detected from phone number\n";
}

// Test with service
echo "\n=== Testing with RobawsExportService ===\n";

try {
    $service = app(\App\Services\Robaws\RobawsExportService::class);
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('detectCountryFromPhoneNumber');
    $method->setAccessible(true);
    
    $serviceResult = $method->invokeArgs($service, [$testData['phone']]);
    
    if ($serviceResult) {
        echo "✅ Service detected country!\n";
        echo "Country: " . $serviceResult['country'] . "\n";
        echo "Country Code: " . $serviceResult['country_code'] . "\n";
    } else {
        echo "❌ Service did not detect country\n";
    }
    
} catch (Exception $e) {
    echo "Error testing service: " . $e->getMessage() . "\n";
}

echo "\nTest completed!\n";

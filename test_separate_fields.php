<?php

require 'vendor/autoload.php';

// Manually load environment and basic config
$_ENV['ROBAWS_BASE_URL'] = 'https://bconnect.robaws.com';
$_ENV['ROBAWS_AUTH'] = 'basic';
$_ENV['ROBAWS_USERNAME'] = 'bconnect';
$_ENV['ROBAWS_PASSWORD'] = 'B123456789@';

use App\Services\Export\Clients\RobawsApiClient;

// Mock Laravel config function
function config($key, $default = null) {
    $configs = [
        'services.robaws.base_url' => $_ENV['ROBAWS_BASE_URL'] ?? 'https://bconnect.robaws.com',
        'services.robaws.api_key' => $_ENV['ROBAWS_API_KEY'] ?? null,
        'services.robaws.auth' => $_ENV['ROBAWS_AUTH'] ?? 'basic',
        'services.robaws.username' => $_ENV['ROBAWS_USERNAME'] ?? 'bconnect',
        'services.robaws.password' => $_ENV['ROBAWS_PASSWORD'] ?? 'B123456789@',
        'services.robaws.timeout' => 30,
        'services.robaws.connect_timeout' => 10,
        'services.robaws.verify_ssl' => true,
    ];
    
    return $configs[$key] ?? $default;
}

$client = new RobawsApiClient();

// Test data with separate firstName and surname
$contactData = [
    'first_name' => 'Ebele',
    'last_name' => 'Efobi',
    'email' => 'ebele.test@efobimotors.com',
    'phone' => '+234 803 456 7890',
    'function' => 'Managing Director',
    'is_primary' => true
];

echo "Testing separate firstName/surname field mapping...\n";
echo "Contact data:\n";
echo "- First Name: " . $contactData['first_name'] . "\n";
echo "- Last Name: " . $contactData['last_name'] . "\n";
echo "- Email: " . $contactData['email'] . "\n\n";

try {
    // Test the contact creation with client ID 4237 (our test client)
    $result = $client->createClientContact(4237, $contactData);
    
    if ($result) {
        echo "SUCCESS: Contact created with separate firstName/surname fields!\n";
        echo "Contact ID: " . ($result['id'] ?? 'N/A') . "\n";
        echo "Response data: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "ERROR: Failed to create contact\n";
    }
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

<?php

require 'vendor/autoload.php';

// Mock Laravel facades and logging globally (before namespace issues)
spl_autoload_register(function ($className) {
    if ($className === 'Illuminate\Support\Facades\Log') {
        // Create an anonymous class to mock Log facade
        return new class {
            public static function info($message, $context = []) {
                echo "LOG: $message\n";
                if (!empty($context)) {
                    echo "Context: " . json_encode($context, JSON_PRETTY_PRINT) . "\n";
                }
            }
            
            public static function error($message, $context = []) {
                echo "ERROR LOG: $message\n";  
                if (!empty($context)) {
                    echo "Context: " . json_encode($context, JSON_PRETTY_PRINT) . "\n";
                }
            }
        };
    }
    
    if ($className === 'Illuminate\Support\Str') {
        return new class {
            public static function uuid() {
                return (object) ['toString' => function() {
                    return 'test-uuid-' . uniqid();
                }];
            }
        };
    }
});

// Mock the Log facade directly
class_alias(MockLog::class, 'Illuminate\Support\Facades\Log');
class_alias(MockStr::class, 'Illuminate\Support\Str');

class MockLog {
    public static function info($message, $context = []) {
        echo "LOG: $message\n";
        if (!empty($context)) {
            echo "Context: " . json_encode($context, JSON_PRETTY_PRINT) . "\n";
        }
    }
    
    public static function error($message, $context = []) {
        echo "ERROR LOG: $message\n";
        if (!empty($context)) {
            echo "Context: " . json_encode($context, JSON_PRETTY_PRINT) . "\n";
        }
    }
}

class MockStr {
    public static function uuid() {
        return (object) ['toString' => function() {
            return 'test-uuid-' . uniqid();
        }];
    }
}

// Mock config function
function config($key, $default = null) {
    $configs = [
        'services.robaws.base_url' => 'https://bconnect.robaws.com',
        'services.robaws.auth' => 'basic',
        'services.robaws.username' => 'bconnect',
        'services.robaws.password' => 'B123456789@',
        'services.robaws.timeout' => 30,
        'services.robaws.connect_timeout' => 10,
        'services.robaws.verify_ssl' => true,
    ];
    
    return $configs[$key] ?? $default;
}

use App\Services\Export\Clients\RobawsApiClient;

$client = new RobawsApiClient();

// Test data with separate firstName and surname
$contactData = [
    'first_name' => 'Ebele',
    'last_name' => 'Efobi',
    'email' => 'ebele.separate@efobimotors.com',
    'phone' => '+234 803 456 7890',
    'function' => 'Managing Director',
    'is_primary' => true
];

echo "Testing separate firstName/surname field mapping...\n";
echo "Contact data:\n";
echo "- First Name: " . $contactData['first_name'] . "\n";
echo "- Last Name: " . $contactData['last_name'] . "\n\n";

try {
    // Show what payload would be sent
    echo "Expected API payload (firstName/surname separate):\n";
    $expectedPayload = [
        'firstName' => $contactData['first_name'],
        'surname' => $contactData['last_name'],
        'email' => $contactData['email'],
        'tel' => $contactData['phone'],
        'function' => $contactData['function'],
        'isPrimary' => $contactData['is_primary'],
        'receivesQuotes' => true,
        'receivesInvoices' => false,
    ];
    echo json_encode($expectedPayload, JSON_PRETTY_PRINT) . "\n\n";
    
    // Test actual contact creation
    echo "Creating contact with client ID 4237...\n";
    $result = $client->createClientContact(4237, $contactData);
    
    if ($result) {
        echo "SUCCESS: Contact created!\n";
        echo "Contact ID: " . ($result['id'] ?? 'N/A') . "\n";
    } else {
        echo "Contact creation failed\n";
    }
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}

<?php

use App\Services\Export\Clients\RobawsApiClient;

echo "Testing contact field mapping with Robaws API...\n\n";

$client = new RobawsApiClient();

// Test different variations of name fields to determine what works
$testCases = [
    [
        'name' => 'Test Case 1: firstName + surname',
        'data' => [
            'firstName' => 'John',
            'surname' => 'Doe',
            'email' => 'john.doe.test1@example.com'
        ]
    ],
    [
        'name' => 'Test Case 2: first_name + last_name (internal format)',
        'data' => [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith.test2@example.com'
        ]
    ],
    [
        'name' => 'Test Case 3: name field only',
        'data' => [
            'name' => 'Full Name Test',
            'email' => 'fullname.test3@example.com'
        ]
    ]
];

foreach ($testCases as $testCase) {
    echo "=== {$testCase['name']} ===\n";
    echo "Input data: " . json_encode($testCase['data'], JSON_PRETTY_PRINT) . "\n";
    
    try {
        $result = $client->createClientContact(4237, $testCase['data']);
        
        if ($result && isset($result['id'])) {
            echo "SUCCESS - Contact created: ID {$result['id']}\n";
            echo "Response fields:\n";
            
            // Check for various name-related fields in response
            $nameFields = ['name', 'firstName', 'surname', 'forename', 'lastName'];
            foreach ($nameFields as $field) {
                if (isset($result[$field])) {
                    echo "  {$field}: '{$result[$field]}'\n";
                }
            }
            
            // Check if any field is null
            $nullFields = [];
            foreach ($nameFields as $field) {
                if (array_key_exists($field, $result) && $result[$field] === null) {
                    $nullFields[] = $field;
                }
            }
            if (!empty($nullFields)) {
                echo "  NULL fields: " . implode(', ', $nullFields) . "\n";
            }
            
        } else {
            echo "FAILED - No contact created\n";
        }
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

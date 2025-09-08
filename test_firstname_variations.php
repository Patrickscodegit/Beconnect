<?php

use App\Services\Export\Clients\RobawsApiClient;

echo "Testing different firstName field variations...\n\n";

$client = new RobawsApiClient();

// Test different field name combinations
$testCases = [
    'Case 1: firstName + surname' => [
        'firstName' => 'TestFirst1',
        'surname' => 'TestSurname1',
        'email' => 'test.case1@example.com'
    ],
    'Case 2: first_name + last_name' => [
        'first_name' => 'TestFirst2',
        'last_name' => 'TestSurname2', 
        'email' => 'test.case2@example.com'
    ],
    'Case 3: name + surname' => [
        'name' => 'TestFirst3',
        'surname' => 'TestSurname3',
        'email' => 'test.case3@example.com'
    ],
    'Case 4: forename + surname' => [
        'forename' => 'TestFirst4',
        'surname' => 'TestSurname4',
        'email' => 'test.case4@example.com'
    ],
];

foreach ($testCases as $caseName => $testData) {
    echo "=== $caseName ===\n";
    echo "Input: " . json_encode($testData, JSON_PRETTY_PRINT) . "\n";
    
    try {
        $result = $client->createClientContact(4237, $testData);
        
        if ($result && isset($result['id'])) {
            echo "SUCCESS: Contact ID " . $result['id'] . "\n";
            echo "Response name fields:\n";
            echo "- firstName: " . ($result['firstName'] ?? 'NULL') . "\n";
            echo "- forename: " . ($result['forename'] ?? 'NULL') . "\n";
            echo "- name: " . ($result['name'] ?? 'NULL') . "\n";
            echo "- surname: " . ($result['surname'] ?? 'NULL') . "\n";
        } else {
            echo "FAILED: No contact created\n";
        }
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

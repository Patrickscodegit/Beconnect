<?php

// Test minimal Carhanco export
$minimalPayload = [
    'title' => 'Test Carhanco Export',
    'clientReference' => 'Test Reference',
    'contactEmail' => 'sales@truck-time.com',
    'clientId' => 4007,
];

echo "Testing minimal payload for Carhanco:\n";
echo json_encode($minimalPayload, JSON_PRETTY_PRINT) . "\n";

$apiClient = new App\Services\Export\Clients\RobawsApiClient();
$result = $apiClient->createQuotation($minimalPayload, 'test_carhanco_minimal_' . time());

echo "\nResult: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
if (!$result['success']) {
    echo "Error: " . ($result['error'] ?? 'unknown') . "\n";
} else {
    echo "Created offer ID: " . ($result['quotation_id'] ?? 'none') . "\n";
}

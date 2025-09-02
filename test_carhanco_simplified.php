<?php

// Test Carhanco with simplified extraFields
$intake = App\Models\Intake::find(2);
$mapper = new App\Services\Export\Mappers\RobawsMapper();
$mapped = $mapper->mapIntakeToRobaws($intake);

// Add client ID
$mapped['client_id'] = 4007;
$mapped['contact_email'] = 'sales@truck-time.com';

// Get the full payload
$payload = $mapper->toRobawsApiPayload($mapped);

echo "Full payload extraFields:\n";
if (isset($payload['extraFields'])) {
    foreach ($payload['extraFields'] as $key => $value) {
        echo "  $key: " . substr(json_encode($value), 0, 100) . (strlen(json_encode($value)) > 100 ? '...' : '') . "\n";
    }
}

// Test without the JSON field (which is very large)
$simplifiedPayload = $payload;
if (isset($simplifiedPayload['extraFields']['JSON'])) {
    echo "\nRemoving large JSON field...\n";
    unset($simplifiedPayload['extraFields']['JSON']);
}

echo "\nTesting without JSON field:\n";
$apiClient = new App\Services\Export\Clients\RobawsApiClient();
$result = $apiClient->createQuotation($simplifiedPayload, 'test_carhanco_simple_' . time());

echo "Result: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
if (!$result['success']) {
    echo "Error: " . ($result['error'] ?? 'unknown') . "\n";
} else {
    echo "Created offer ID: " . ($result['quotation_id'] ?? 'none') . "\n";
}

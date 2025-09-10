<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Intake;
use App\Services\Export\Clients\RobawsApiClient;
use App\Services\Export\Mappers\RobawsMapper;

echo "=== TESTING DOC-ALIGNED ROBAWS APPROACH ===" . PHP_EOL;

$intake = Intake::find(11);
if (!$intake) {
    echo 'Intake not found' . PHP_EOL;
    exit(1);
}

echo 'Intake ID: ' . $intake->id . PHP_EOL;
echo 'Customer: ' . $intake->customer_name . PHP_EOL . PHP_EOL;

// Initialize services
$apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
$mapper = new \App\Services\Export\Mappers\RobawsMapper($apiClient);

// Map the intake to Robaws format
$mapped = $mapper->mapIntakeToRobaws($intake);

echo "=== CUSTOMER DATA ===" . PHP_EOL;
echo 'Customer in quotation_info: ' . ($mapped['quotation_info']['customer'] ?? 'N/A') . PHP_EOL;
echo 'Enhanced customer data available: ' . (empty($mapped['customer_data']) ? 'NO' : 'YES') . PHP_EOL;

// Test client resolution/update
$clientId = $apiClient->findClientId(
    $mapped['quotation_info']['customer'] ?? null,
    $mapped['quotation_info']['contact_email'] ?? null
);
echo 'Found client ID: ' . ($clientId ?: 'NONE') . PHP_EOL . PHP_EOL;

// Test API payload generation
$payload = $mapper->toRobawsApiPayload($mapped);
echo "=== API PAYLOAD ANALYSIS ===" . PHP_EOL;
echo 'Client linking fields:' . PHP_EOL;
echo '  - clientId: ' . ($payload['clientId'] ?? 'null') . PHP_EOL;
echo '  - client_id: ' . ($payload['client_id'] ?? 'null') . PHP_EOL; 
echo '  - contact_id: ' . ($payload['contact_id'] ?? 'null') . PHP_EOL . PHP_EOL;

echo 'Template placeholders (client fields):' . PHP_EOL;
$count = 0;
foreach(($payload['extraFields'] ?? []) as $code => $field) {
    if (strpos($code, 'client') === 0 && $count < 8) {
        echo '  - ' . $code . ': ' . ($field['stringValue'] ?? 'null') . PHP_EOL;
        $count++;
    }
}

echo PHP_EOL . "=== TEST CLIENT UPDATE ===" . PHP_EOL;
if ($clientId) {
    try {
        $customerData = [
            'name' => 'Armos BV',
            'vatNumber' => 'BE0437311533',
            'website' => 'www.armos.be',
            'street' => 'Kapelsesteenweg 611',
            'city' => 'Antwerp (Ekeren)',
            'postal_code' => 'B-2180',
            'country' => 'Belgium'
        ];
        
        echo 'Attempting doc-aligned client update...' . PHP_EOL;
        $result = $apiClient->updateClient($clientId, $customerData);
        
        if ($result && isset($result['id'])) {
            echo 'SUCCESS: Client updated without 415 errors!' . PHP_EOL;
            echo 'Updated client ID: ' . $result['id'] . PHP_EOL;
        } else {
            echo 'WARNING: Update result was null or missing ID' . PHP_EOL;
        }
        
    } catch (\Exception $e) {
        echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    }
} else {
    echo 'SKIPPED: No client ID found' . PHP_EOL;
}

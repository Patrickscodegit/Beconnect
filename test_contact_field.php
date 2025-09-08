<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$api = app(\App\Services\Export\Clients\RobawsApiClient::class);
$offerId = 11777;

echo "=== Testing Contact Linking with Correct Field Names ===\n";

try {
    // Get offer first to see current state
    $offer = $api->getOffer((string)$offerId);
    echo "Current clientContactId: " . ($offer['data']['clientContactId'] ?? 'null') . "\n";
    
    // Try to set contact using contact ID 5397
    $contactId = 5397;
    echo "Attempting to set contact $contactId on offer $offerId...\n";
    
    $result = $api->setOfferContact($offerId, $contactId);
    echo "Set contact result: " . ($result ? 'Success' : 'Failed') . "\n";
    
    // Verify by fetching again
    $offer = $api->getOffer((string)$offerId);
    echo "After setting - clientContactId: " . ($offer['data']['clientContactId'] ?? 'null') . "\n";
    
    // Also check for other possible contact fields
    $possibleFields = ['clientContactId', 'contactPersonId', 'contactId', 'endClientContactId'];
    foreach ($possibleFields as $field) {
        if (isset($offer['data'][$field]) && $offer['data'][$field] !== null) {
            echo "Found contact in field '$field': " . $offer['data'][$field] . "\n";
        }
    }
    
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

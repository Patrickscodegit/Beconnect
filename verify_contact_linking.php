<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$api = app(\App\Services\Export\Clients\RobawsApiClient::class);
$offerId = 11777;

echo "=== Verifying Contact Linking for Offer $offerId ===\n";

// 1) Read offer and try to see the linked contact, regardless of field shape
try {
    $offer = $api->getOffer((string)$offerId, ['client','contact']);
    $contact      = data_get($offer, 'data.contact') ?? data_get($offer, 'data.contactPerson') ?? data_get($offer, 'data.clientContact');
    $contactId    = data_get($offer, 'data.clientContactId') ?? data_get($offer, 'data.contactId') ?? data_get($offer, 'data.contactPersonId');
    $clientId     = (int) data_get($offer, 'data.client.id');

    // Print what we got
    echo "Contact ID: " . ($contactId ?: 'None') . "\n";
    echo "Client ID: " . $clientId . "\n";
    echo "Contact object: " . (($contact) ? json_encode($contact, JSON_PRETTY_PRINT) : 'None') . "\n";

    // 2) If still empty, re-link explicitly using your extracted contact data
    $intake = \App\Models\Intake::where('robaws_offer_id', $offerId)->first();
    if ($intake && $intake->extraction_data) {
        $contactData = app(\App\Services\Robaws\RobawsExportService::class)->extractContactPersonData($intake->extraction_data);
        echo "\nExtracted contact data: " . json_encode($contactData, JSON_PRETTY_PRINT) . "\n";
        
        if ($clientId && !empty($contactData)) {
            echo "\nAttempting to find/create contact...\n";
            $foundId = $api->findOrCreateClientContactId($clientId, $contactData);
            echo "Found/Created contact ID: " . ($foundId ?: 'None') . "\n";
            
            if ($foundId) {
                echo "Setting contact on offer...\n";
                $result = $api->setOfferContact($offerId, $foundId);
                echo "Set contact result: " . ($result ? 'Success' : 'Failed') . "\n";
                
                if ($result) {
                    echo "Re-fetching offer to verify...\n";
                    $offer = $api->getOffer((string)$offerId, ['client','contact']); // re-check
                    $newContactId = data_get($offer, 'data.clientContactId') ?? data_get($offer, 'data.contactId') ?? data_get($offer, 'data.contactPersonId');
                    $newContact = data_get($offer, 'data.contact') ?? data_get($offer, 'data.contactPerson') ?? data_get($offer, 'data.clientContact');
                    echo "After re-link - Contact ID: " . ($newContactId ?: 'None') . "\n";
                    echo "After re-link - Contact: " . (($newContact) ? json_encode($newContact, JSON_PRETTY_PRINT) : 'None') . "\n";
                }
            }
        }
    }

    // Show the O-number for UI cross-check
    $oNumber = "O" . (239719 + $offerId);
    echo "\nUI Cross-check: Look for offer $oNumber in Robaws interface\n";

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

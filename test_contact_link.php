<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = app(App\Services\Export\Clients\RobawsApiClient::class);
$result = $client->getOffer('11785');
$data = $result['data'] ?? [];

echo "Offer verification:\n";
echo "Client ID: " . ($data['clientId'] ?? 'none') . "\n";
echo "Contact Email: " . ($data['contactEmail'] ?? 'none') . "\n";
echo "Client Contact ID: " . ($data['clientContactId'] ?? 'none') . "\n";
echo "Contact Person ID: " . ($data['contactPersonId'] ?? 'none') . "\n";
echo "Contact ID: " . ($data['contactId'] ?? 'none') . "\n";

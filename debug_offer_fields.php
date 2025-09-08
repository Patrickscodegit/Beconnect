<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$api = app(\App\Services\Export\Clients\RobawsApiClient::class);
$offerId = 11777;

echo "=== Full Offer Data Analysis ===\n";

try {
    // Get full offer data without filters to see all available fields
    $offer = $api->getOffer((string)$offerId);
    
    echo "Raw offer data:\n";
    echo json_encode($offer, JSON_PRETTY_PRINT) . "\n";
    
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

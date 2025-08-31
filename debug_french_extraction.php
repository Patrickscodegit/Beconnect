<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Extraction\HybridExtractionPipeline;

echo "=== DEBUGGING FRENCH EMAIL EXTRACTION ===\n\n";

$frenchContent = "Bonjour, je suis Badr algothami et je veux transporter une BMW Série 7 de Bruxelles vers Djeddah par ferry.";

echo "Input: $frenchContent\n\n";

$pipeline = app(HybridExtractionPipeline::class);
$result = $pipeline->extract($frenchContent, 'email');
$data = $result['data'] ?? [];

echo "=== FULL EXTRACTION OUTPUT ===\n";
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "\n\n";

echo "=== SPECIFIC PATHS ===\n";
echo "Legacy shipment.origin: " . (data_get($data, 'shipment.origin') ?: 'NULL') . "\n";
echo "Legacy shipment.destination: " . (data_get($data, 'shipment.destination') ?: 'NULL') . "\n";
echo "Modern shipping.route.origin.city: " . (data_get($data, 'shipping.route.origin.city') ?: 'NULL') . "\n";
echo "Modern shipping.route.destination.city: " . (data_get($data, 'shipping.route.destination.city') ?: 'NULL') . "\n";
echo "Contact name: " . (data_get($data, 'contact.name') ?: 'NULL') . "\n";
echo "Vehicle brand: " . (data_get($data, 'vehicle.brand') ?: 'NULL') . "\n";
echo "Vehicle model: " . (data_get($data, 'vehicle.model') ?: 'NULL') . "\n\n";

echo "=== CHECKING AI EXTRACTOR OUTPUT ===\n";
// Let's see what the AI extractor is returning before normalization
$aiExtractor = app(\App\Services\Extraction\AiExtractor::class);
$aiResult = $aiExtractor->extract($frenchContent, 'email');
echo "Raw AI result:\n";
echo json_encode($aiResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "\n\n";

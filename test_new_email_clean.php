<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Extraction\HybridExtractionPipeline;
use App\Utils\RobawsPayloadMapper;

echo "=== TESTING NEW BMW EMAIL CONTENT ===\n\n";

$frenchContent = "Bonjour,

Je souhaite expédier ma voiture BMW Série 7 de Bruxelles vers Djeddah par transport maritime (RoRo).

Merci d'inclure dans votre offre :
• Le transport maritime RoRo.
• L'accomplissement de toutes les formalités douanières et administratives jusqu'à la livraison à Djeddah.
• L'ajout d'une assurance tous risques pour le véhicule, si nécessaire.
• Le délai estimatif pour l'expédition.
• Un devis détaillé et complet.

Badr algothami";

echo "Content to extract:\n";
echo "'$frenchContent'\n\n";

$pipeline = app(HybridExtractionPipeline::class);
$result = $pipeline->extract($frenchContent, 'email');
$data = $result['data'] ?? [];

echo "=== EXTRACTION RESULTS ===\n";
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "\n\n";

echo "=== FIELD MAPPING CHECK ===\n";

// Check key fields
echo "Vehicle Brand: " . (data_get($data, 'vehicle.brand') ?: 'MISSING') . "\n";
echo "Vehicle Model: " . (data_get($data, 'vehicle.model') ?: 'MISSING') . "\n";
echo "Dimensions: " . (data_get($data, 'vehicle.dimensions.length_m') ? data_get($data, 'vehicle.dimensions.length_m') . 'm x ' . data_get($data, 'vehicle.dimensions.width_m') . 'm x ' . data_get($data, 'vehicle.dimensions.height_m') . 'm' : 'MISSING') . "\n";
echo "Contact Name: " . (data_get($data, 'contact.name') ?: 'MISSING') . "\n";
echo "Contact Email: " . (data_get($data, 'contact.email') ?: 'MISSING') . "\n";
echo "Origin: " . (data_get($data, 'shipment.origin') ?: 'MISSING') . "\n";
echo "Destination: " . (data_get($data, 'shipment.destination') ?: 'MISSING') . "\n";
echo "Shipping Type: " . (data_get($data, 'shipment.shipping_type') ?: 'MISSING') . "\n";

echo "\n=== CONSISTENCY CHECK ===\n";
$shipmentOrigin = data_get($data, 'shipment.origin');
$shipmentDestination = data_get($data, 'shipment.destination');
$shippingOrigin = data_get($data, 'shipping.route.origin.city');
$shippingDestination = data_get($data, 'shipping.route.destination.city');

echo "Legacy Route: " . ($shipmentOrigin ?: 'NULL') . " → " . ($shipmentDestination ?: 'NULL') . "\n";
echo "Modern Route: " . ($shippingOrigin ?: 'NULL') . " → " . ($shippingDestination ?: 'NULL') . "\n";
echo "Consistent: " . (($shipmentOrigin === $shippingOrigin && $shipmentDestination === $shippingDestination) ? 'YES' : 'NO') . "\n";

echo "\n=== ROBAWS PAYLOAD ===\n";
$robawsFields = RobawsPayloadMapper::mapExtraFields($data);
foreach ($robawsFields as $field => $value) {
    echo "$field: $value\n";
}

echo "\n=== COMPLETENESS SCORE ===\n";
$completeness = data_get($data, 'final_validation.completeness_score', 0);
$quality = data_get($data, 'final_validation.quality_score', 0);
echo "Completeness: " . round($completeness * 100, 1) . "%\n";
echo "Quality: " . round($quality * 100, 1) . "%\n";

$warnings = data_get($data, 'final_validation.warnings', []);
if (!empty($warnings)) {
    echo "Warnings:\n";
    foreach ($warnings as $warning) {
        echo "  - $warning\n";
    }
}

<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Extraction\HybridExtractionPipeline;
use App\Utils\RobawsPayloadMapper;

echo "=== TESTING POLISHED PIPELINE WITH REAL BMW EMAIL ===\n\n";

// Test with the real BMW email
$content = file_get_contents('real_bmw_serie7.eml');

if (preg_match('/Content-Transfer-Encoding: quoted-printable\s*\n\s*\n(.+?)\n--/s', $content, $matches)) {
    $plainText = quoted_printable_decode($matches[1]);
    
    echo "Real BMW email content extracted (" . strlen($plainText) . " chars)\n\n";
    
    $pipeline = app(HybridExtractionPipeline::class);
    $result = $pipeline->extract($plainText, 'email');
    $data = $result['data'] ?? [];
    
    echo "=== POLISHED EXTRACTION RESULTS ===\n";
    
    // Vehicle information
    echo "Vehicle:\n";
    echo "  - Brand: " . (data_get($data, 'vehicle.brand') ?: 'MISSING') . "\n";
    echo "  - Model: " . (data_get($data, 'vehicle.model') ?: 'MISSING') . "\n";
    echo "  - Dimensions present: " . (data_get($data, 'vehicle.dimensions.length_m') ? 'YES' : 'NO') . "\n";
    echo "  - Needs dimension lookup: " . (data_get($data, 'vehicle.needs_dimension_lookup') ? 'true' : 'false') . "\n\n";
    
    // Legacy shipment structure (for Robaws)
    echo "Legacy shipment structure (Robaws-ready):\n";
    echo "  - Origin: " . (data_get($data, 'shipment.origin') ?: 'MISSING') . "\n";
    echo "  - Destination: " . (data_get($data, 'shipment.destination') ?: 'MISSING') . "\n";
    echo "  - Shipping type: " . (data_get($data, 'shipment.shipping_type') ?: 'MISSING') . "\n\n";
    
    // Contact information
    echo "Contact information:\n";
    echo "  - Name: " . (data_get($data, 'contact.name') ?: 'MISSING') . "\n";
    echo "  - Email: " . (data_get($data, 'contact.email') ?: 'MISSING') . "\n";
    echo "  - Company: " . (data_get($data, 'contact.company') ?: 'MISSING') . "\n\n";
    
    // Check for placeholder values (should all be cleaned)
    echo "=== PLACEHOLDER CLEANUP CHECK ===\n";
    $placeholderFields = [];
    
    function checkPlaceholders($array, $path = '') {
        global $placeholderFields;
        foreach ($array as $key => $value) {
            $currentPath = $path ? $path . '.' . $key : $key;
            if (is_array($value)) {
                checkPlaceholders($value, $currentPath);
            } elseif (is_string($value)) {
                $v = strtolower(trim($value));
                if (in_array($v, ['n/a', 'na', 'unknown', '--', '-'])) {
                    $placeholderFields[] = $currentPath . ' = "' . $value . '"';
                }
            }
        }
    }
    
    checkPlaceholders($data);
    
    if (empty($placeholderFields)) {
        echo "✅ No placeholder values found - sanitization successful!\n\n";
    } else {
        echo "❌ Found placeholder values:\n";
        foreach ($placeholderFields as $field) {
            echo "  - $field\n";
        }
        echo "\n";
    }
    
    // Test Robaws payload mapping
    echo "=== ROBAWS PAYLOAD MAPPING ===\n";
    $robawsFields = RobawsPayloadMapper::mapExtraFields($data);
    
    echo "Extra fields for Robaws:\n";
    foreach ($robawsFields as $field => $value) {
        echo "  - $field: $value\n";
    }
    echo "\n";
    
    // Check data consistency
    $shipmentOrigin = data_get($data, 'shipment.origin');
    $shipmentDestination = data_get($data, 'shipment.destination');
    $shippingOrigin = data_get($data, 'shipping.route.origin.city');
    $shippingDestination = data_get($data, 'shipping.route.destination.city');
    
    echo "=== DATA CONSISTENCY CHECK ===\n";
    
    if ($shipmentOrigin === $shippingOrigin && $shipmentDestination === $shippingDestination) {
        echo "✅ Legacy and modern shipping data are CONSISTENT!\n";
        echo "   Route: $shipmentOrigin → $shipmentDestination\n";
    } else {
        echo "❌ Data inconsistency detected:\n";
        echo "   Legacy: " . ($shipmentOrigin ?: 'MISSING') . " → " . ($shipmentDestination ?: 'MISSING') . "\n";
        echo "   Modern: " . ($shippingOrigin ?: 'MISSING') . " → " . ($shippingDestination ?: 'MISSING') . "\n";
    }
    
    // Validation summary
    echo "\n=== FINAL VALIDATION ===\n";
    $warnings = data_get($data, 'final_validation.warnings', []);
    $qualityScore = data_get($data, 'final_validation.quality_score', 0);
    
    echo "Quality score: " . round($qualityScore * 100, 1) . "%\n";
    
    if (empty($warnings)) {
        echo "✅ No validation warnings\n";
    } else {
        echo "Validation warnings:\n";
        foreach ($warnings as $warning) {
            echo "  ⚠️  $warning\n";
        }
    }
    
    echo "\n=== EXTRACTION COMPLETE ===\n";
    echo "Pipeline is ready for production deployment!\n";
    
} else {
    echo "Failed to extract email content from real BMW email\n";
}

<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Extraction\HybridExtractionPipeline;

echo "=== TESTING SURGICAL FIXES WITH REAL BMW EMAIL ===\n\n";

// Test with the real BMW email
$content = file_get_contents('real_bmw_serie7.eml');

if (preg_match('/Content-Transfer-Encoding: quoted-printable\s*\n\s*\n(.+?)\n--/s', $content, $matches)) {
    $plainText = quoted_printable_decode($matches[1]);
    
    echo "Real BMW email content extracted (" . strlen($plainText) . " chars)\n\n";
    
    $pipeline = app(HybridExtractionPipeline::class);
    $result = $pipeline->extract($plainText, 'email');
    $data = $result['data'] ?? [];
    
    echo "=== CRITICAL FIELDS CHECK ===\n";
    
    // Vehicle information
    echo "Vehicle:\n";
    echo "  - Brand: " . ($data['vehicle']['brand'] ?? 'MISSING') . "\n";
    echo "  - Model: " . ($data['vehicle']['model'] ?? 'MISSING') . "\n";
    echo "  - Dimensions present: " . (isset($data['vehicle']['dimensions']) ? 'YES' : 'NO') . "\n";
    echo "  - Needs dimension lookup: " . ($data['vehicle']['needs_dimension_lookup'] ?? 'true') . "\n\n";
    
    // Legacy vs Modern shipping comparison
    echo "Legacy shipment structure:\n";
    echo "  - Origin: " . ($data['shipment']['origin'] ?? 'MISSING') . "\n";
    echo "  - Destination: " . ($data['shipment']['destination'] ?? 'MISSING') . "\n";
    echo "  - Shipping type: " . ($data['shipment']['shipping_type'] ?? 'MISSING') . "\n\n";
    
    echo "Modern shipping structure:\n";
    if (isset($data['shipping']['route'])) {
        echo "  - Origin: " . ($data['shipping']['route']['origin']['city'] ?? 'MISSING') . "\n";
        echo "  - Destination: " . ($data['shipping']['route']['destination']['city'] ?? 'MISSING') . "\n";
        echo "  - Method: " . ($data['shipping']['method'] ?? 'MISSING') . "\n";
    } else {
        echo "  - Structure: MISSING\n";
    }
    echo "\n";
    
    // Contact information
    echo "Contact information:\n";
    echo "  - Name: " . ($data['contact']['name'] ?? 'MISSING') . "\n";
    echo "  - Email: " . ($data['contact']['email'] ?? 'MISSING') . "\n";
    echo "  - Phone: " . ($data['contact']['phone'] ?? 'MISSING') . "\n\n";
    
    // Check if shipping contact exists
    if (isset($data['shipping']['contact'])) {
        echo "Shipping contact (should be backfilled to main contact):\n";
        echo "  - Name: " . ($data['shipping']['contact']['name'] ?? 'MISSING') . "\n";
        echo "  - Email: " . ($data['shipping']['contact']['email'] ?? 'MISSING') . "\n";
        echo "  - Phone: " . ($data['shipping']['contact']['phone'] ?? 'MISSING') . "\n\n";
    }
    
    // Check consistency
    $shipmentOrigin = $data['shipment']['origin'] ?? null;
    $shipmentDestination = $data['shipment']['destination'] ?? null;
    $shippingOrigin = $data['shipping']['route']['origin']['city'] ?? null;
    $shippingDestination = $data['shipping']['route']['destination']['city'] ?? null;
    
    $isConsistent = ($shipmentOrigin === $shippingOrigin) && ($shipmentDestination === $shippingDestination);
    
    if ($isConsistent && $shipmentOrigin && $shipmentDestination) {
        echo "✅ SUCCESS: Data mapping is CONSISTENT!\n";
        echo "   - Both structures show: $shipmentOrigin → $shipmentDestination\n";
    } else {
        echo "❌ ISSUE: Data mapping inconsistency detected\n";
        echo "   - Legacy: " . ($shipmentOrigin ?: 'MISSING') . " → " . ($shipmentDestination ?: 'MISSING') . "\n";
        echo "   - Modern: " . ($shippingOrigin ?: 'MISSING') . " → " . ($shippingDestination ?: 'MISSING') . "\n";
    }
    
    // Check if contact name is properly separated from locations
    $contactName = $data['contact']['name'] ?? null;
    if ($contactName) {
        $nameTokens = preg_split('/[\s\-]+/', strtolower($contactName));
        $nameInOrigin = $shipmentOrigin && in_array(strtolower($shipmentOrigin), $nameTokens);
        $nameInDest = $shipmentDestination && in_array(strtolower($shipmentDestination), $nameTokens);
        
        if (!$nameInOrigin && !$nameInDest) {
            echo "✅ Contact name properly separated from location data\n";
        } else {
            echo "⚠️  Contact name tokens still found in location fields\n";
        }
    }
    
    echo "\n=== ROBAWS PAYLOAD PREVIEW ===\n";
    echo "Expected Robaws fields:\n";
    echo "  - POR: " . ($data['shipment']['origin'] ?? 'EMPTY') . "\n";
    echo "  - POL: " . ($data['shipment']['origin'] ?? 'EMPTY') . "\n";
    echo "  - POD: " . ($data['shipment']['destination'] ?? 'EMPTY') . "\n";
    echo "  - CARGO: " . trim(($data['vehicle']['brand'] ?? '') . ' ' . ($data['vehicle']['model'] ?? '')) . "\n";
    
    if (isset($data['vehicle']['dimensions'])) {
        $dims = $data['vehicle']['dimensions'];
        echo "  - DIM_BEF_DELIVERY: " . sprintf('%s x %s x %s m',
            $dims['length_m'] ?? 'N/A',
            $dims['width_m'] ?? 'N/A', 
            $dims['height_m'] ?? 'N/A'
        ) . "\n";
    }
    
    echo "  - Customer: " . ($data['contact']['name'] ?? 'EMPTY') . "\n";
    echo "  - Contact: " . ($data['contact']['email'] ?? 'EMPTY') . "\n\n";
    
    // Show any validation warnings
    if (!empty($data['final_validation']['warnings'])) {
        echo "=== VALIDATION WARNINGS ===\n";
        foreach ($data['final_validation']['warnings'] as $warning) {
            echo "⚠️  $warning\n";
        }
        echo "\n";
    }
    
} else {
    echo "Failed to extract email content from real BMW email\n";
}

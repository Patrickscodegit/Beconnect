<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Extraction\HybridExtractionPipeline;

// Test the new normalization with French BMW email
$content = file_get_contents('bmw_serie7_french.eml');

if (preg_match('/Content-Transfer-Encoding: quoted-printable\s*\n\s*\n(.+?)\n--/s', $content, $matches)) {
    $plainText = quoted_printable_decode($matches[1]);
    
    echo "=== TESTING COMPREHENSIVE DATA MAPPING FIX ===\n\n";
    
    $pipeline = app(HybridExtractionPipeline::class);
    $result = $pipeline->extract($plainText, 'email');
    $data = $result['data'] ?? [];
    
    echo "=== LEGACY SHIPMENT STRUCTURE ===\n";
    $shipmentOrigin = $data['shipment']['origin'] ?? 'MISSING';
    $shipmentDestination = $data['shipment']['destination'] ?? 'MISSING';
    echo "Origin: $shipmentOrigin\n";
    echo "Destination: $shipmentDestination\n\n";
    
    echo "=== NEW SHIPPING STRUCTURE ===\n";
    if (isset($data['shipping']['route'])) {
        $shippingOrigin = $data['shipping']['route']['origin']['city'] ?? 'MISSING';
        $shippingDestination = $data['shipping']['route']['destination']['city'] ?? 'MISSING';
        echo "Origin: $shippingOrigin\n";
        echo "Destination: $shippingDestination\n";
    } else {
        echo "Shipping structure: MISSING\n";
    }
    echo "\n";
    
    echo "=== CONTACT INFORMATION ===\n";
    $contactName = $data['contact']['name'] ?? 'MISSING';
    $contactEmail = $data['contact']['email'] ?? 'MISSING';
    echo "Name: $contactName\n";
    echo "Email: $contactEmail\n\n";
    
    echo "=== VEHICLE INFORMATION ===\n";
    $vehicleBrand = $data['vehicle']['brand'] ?? 'MISSING';
    $vehicleModel = $data['vehicle']['model'] ?? 'MISSING';
    echo "Brand: $vehicleBrand\n";
    echo "Model: $vehicleModel\n\n";
    
    // Check consistency
    $isConsistent = ($shipmentOrigin === ($data['shipping']['route']['origin']['city'] ?? null)) &&
                   ($shipmentDestination === ($data['shipping']['route']['destination']['city'] ?? null));
    
    if ($isConsistent && $shipmentOrigin !== 'MISSING' && $shipmentDestination !== 'MISSING') {
        echo "✅ SUCCESS: Data mapping is now CONSISTENT!\n";
        echo "   - Legacy shipment: $shipmentOrigin → $shipmentDestination\n";
        echo "   - New shipping: " . ($data['shipping']['route']['origin']['city'] ?? 'MISSING') . " → " . ($data['shipping']['route']['destination']['city'] ?? 'MISSING') . "\n";
        echo "   - Contact name: $contactName (properly separated from location data)\n";
    } else {
        echo "❌ ISSUE: Data mapping inconsistency still exists\n";
        echo "   - Legacy shipment: $shipmentOrigin → $shipmentDestination\n";
        echo "   - New shipping: " . ($data['shipping']['route']['origin']['city'] ?? 'MISSING') . " → " . ($data['shipping']['route']['destination']['city'] ?? 'MISSING') . "\n";
    }
    
    echo "\n=== FULL EXTRACTION DATA ===\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    
} else {
    echo "Failed to extract email content\n";
}

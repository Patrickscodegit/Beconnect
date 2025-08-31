<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Extraction\HybridExtractionPipeline;

echo "=== TESTING REAL BMW SÉRIE 7 EMAIL ===\n\n";

// Use the real email file
$content = file_get_contents('/Users/patrickhome/Downloads/68b320c74d202_01K3XVG2MBHB27G6HHDHBT47RX.eml');

if (!$content) {
    echo "❌ Failed to read the real email file\n";
    exit(1);
}

// Extract plain text content from the multipart email
if (preg_match('/Content-Type: text\/plain.*?Content-Transfer-Encoding: quoted-printable\s*\n\s*\n(.+?)\n--Apple-Mail/s', $content, $matches)) {
    $plainText = quoted_printable_decode($matches[1]);
    
    echo "✅ Successfully extracted plain text content (" . strlen($plainText) . " chars)\n";
    echo "Plain text preview:\n";
    echo str_repeat('-', 50) . "\n";
    echo substr($plainText, 0, 300) . "...\n";
    echo str_repeat('-', 50) . "\n\n";
    
    $pipeline = app(HybridExtractionPipeline::class);
    $result = $pipeline->extract($plainText, 'email');
    $data = $result['data'] ?? [];
    
    echo "=== EXTRACTION RESULTS ANALYSIS ===\n";
    
    // Check legacy shipment structure
    echo "LEGACY SHIPMENT STRUCTURE:\n";
    $shipmentOrigin = $data['shipment']['origin'] ?? 'MISSING';
    $shipmentDestination = $data['shipment']['destination'] ?? 'MISSING';
    $shipmentType = $data['shipment']['shipping_type'] ?? 'MISSING';
    echo "  - Origin: $shipmentOrigin\n";
    echo "  - Destination: $shipmentDestination\n";
    echo "  - Type: $shipmentType\n\n";
    
    // Check new shipping structure
    echo "NEW SHIPPING STRUCTURE:\n";
    if (isset($data['shipping']['route'])) {
        $shippingOrigin = $data['shipping']['route']['origin']['city'] ?? 'MISSING';
        $shippingDestination = $data['shipping']['route']['destination']['city'] ?? 'MISSING';
        echo "  - Origin: $shippingOrigin\n";
        echo "  - Destination: $shippingDestination\n";
    } else {
        echo "  - Route: MISSING\n";
    }
    
    if (isset($data['shipping']['method'])) {
        echo "  - Method: " . $data['shipping']['method'] . "\n";
    }
    echo "\n";
    
    // Check contact information
    echo "CONTACT INFORMATION:\n";
    $contactName = $data['contact']['name'] ?? 'MISSING';
    $contactEmail = $data['contact']['email'] ?? 'MISSING';
    echo "  - Name: $contactName\n";
    echo "  - Email: $contactEmail\n\n";
    
    // Check vehicle information
    echo "VEHICLE INFORMATION:\n";
    $vehicleBrand = $data['vehicle']['brand'] ?? 'MISSING';
    $vehicleModel = $data['vehicle']['model'] ?? 'MISSING';
    echo "  - Brand: $vehicleBrand\n";
    echo "  - Model: $vehicleModel\n\n";
    
    // Analyze the data mapping consistency
    echo "=== DATA MAPPING CONSISTENCY CHECK ===\n";
    
    $hasShippingRoute = isset($data['shipping']['route']);
    $shipmentPopulated = $shipmentOrigin !== 'MISSING' && $shipmentDestination !== 'MISSING';
    
    if ($hasShippingRoute && $shipmentPopulated) {
        $shippingOriginCity = $data['shipping']['route']['origin']['city'] ?? null;
        $shippingDestinationCity = $data['shipping']['route']['destination']['city'] ?? null;
        
        $isConsistent = ($shipmentOrigin === $shippingOriginCity) && 
                       ($shipmentDestination === $shippingDestinationCity);
        
        if ($isConsistent) {
            echo "✅ SUCCESS: Data mapping is CONSISTENT!\n";
            echo "   Both structures show: $shipmentOrigin → $shipmentDestination\n";
        } else {
            echo "❌ INCONSISTENCY DETECTED:\n";
            echo "   Legacy shipment: $shipmentOrigin → $shipmentDestination\n";
            echo "   New shipping: $shippingOriginCity → $shippingDestinationCity\n";
        }
    } else {
        echo "⚠️  INCOMPLETE DATA:\n";
        echo "   Legacy shipment populated: " . ($shipmentPopulated ? 'YES' : 'NO') . "\n";
        echo "   New shipping route exists: " . ($hasShippingRoute ? 'YES' : 'NO') . "\n";
    }
    
    // Check if contact names are incorrectly mapped to locations
    if ($contactName !== 'MISSING') {
        $nameTokens = preg_split('/[\s\-]+/', mb_strtolower($contactName));
        $contactAsLocation = false;
        
        if (in_array(mb_strtolower($shipmentOrigin), $nameTokens) || 
            in_array(mb_strtolower($shipmentDestination), $nameTokens)) {
            echo "\n❌ CRITICAL ISSUE: Contact name tokens found in shipment locations!\n";
            echo "   Contact: $contactName\n";
            echo "   Shipment: $shipmentOrigin → $shipmentDestination\n";
            $contactAsLocation = true;
        }
        
        if (!$contactAsLocation) {
            echo "\n✅ Contact data properly separated from location data\n";
        }
    }
    
    echo "\n=== FULL EXTRACTION RESULT ===\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
} else {
    echo "❌ Failed to extract plain text from the real email\n";
    echo "Email content preview (first 500 chars):\n";
    echo substr($content, 0, 500) . "\n";
}

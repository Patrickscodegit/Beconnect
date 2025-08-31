<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Extraction\HybridExtractionPipeline;
use App\Utils\RobawsPayloadMapper;

echo "=== TESTING NEW BMW EMAIL ===\n\n";

// Read the new email content
$emailPath = '/Users/patrickhome/Downloads/68b320c74d202_01K3XVG2MBHB27G6HHDHBT47RX.eml';
$content = file_get_contents($emailPath);

// Extract the plain text content from the multipart email
if (preg_match('/Content-Type: text\/plain.*?\n\n(.+?)\n\n--Apple-Mail/s', $content, $matches)) {
    $plainText = quoted_printable_decode($matches[1]);
    
    echo "Email content extracted (" . strlen($plainText) . " chars):\n";
    echo "'" . $plainText . "'\n\n";
    
    $pipeline = app(HybridExtractionPipeline::class);
    $result = $pipeline->extract($plainText, 'email');
    $data = $result['data'] ?? [];
    
    echo "=== EXTRACTION RESULTS ===\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo "\n\n";
    
    echo "=== FIELD MAPPING CHECK ===\n";
    
    // Check key fields
    echo "Vehicle Brand: " . (data_get($data, 'vehicle.brand') ?: 'MISSING') . "\n";
    echo "Vehicle Model: " . (data_get($data, 'vehicle.model') ?: 'MISSING') . "\n";
    echo "Dimensions: " . (data_get($data, 'vehicle.dimensions.length_m') ? 'YES' : 'NO') . "\n";
    echo "Contact Name: " . (data_get($data, 'contact.name') ?: 'MISSING') . "\n";
    echo "Contact Email: " . (data_get($data, 'contact.email') ?: 'MISSING') . "\n";
    echo "Origin: " . (data_get($data, 'shipment.origin') ?: 'MISSING') . "\n";
    echo "Destination: " . (data_get($data, 'shipment.destination') ?: 'MISSING') . "\n";
    echo "Shipping Type: " . (data_get($data, 'shipment.shipping_type') ?: 'MISSING') . "\n";
    
    echo "\n=== ROBAWS PAYLOAD ===\n";
    $robawsFields = RobawsPayloadMapper::mapExtraFields($data);
    foreach ($robawsFields as $field => $value) {
        echo "$field: $value\n";
    }
    
} else {
    echo "Failed to extract email content\n";
    
    // Try alternative extraction
    if (preg_match('/Content-Transfer-Encoding: quoted-printable\s*\n\s*\n(.+?)\n--/s', $content, $matches)) {
        $plainText = quoted_printable_decode($matches[1]);
        echo "Alternative extraction found (" . strlen($plainText) . " chars):\n";
        echo "'" . $plainText . "'\n";
    }
}

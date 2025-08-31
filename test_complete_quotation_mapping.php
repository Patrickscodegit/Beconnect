<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Extraction\HybridExtractionPipeline;
use App\Utils\RobawsPayloadMapper;

echo "=== COMPLETE EMAIL PROCESSING TEST ===\n\n";

// Read the full email with headers
$emailPath = '/Users/patrickhome/Downloads/68b320c74d202_01K3XVG2MBHB27G6HHDHBT47RX.eml';
$fullEmail = file_get_contents($emailPath);

// Extract sender email from headers
$senderEmail = null;
if (preg_match('/From:.*?<(.+?)>/', $fullEmail, $matches)) {
    $senderEmail = $matches[1];
} elseif (preg_match('/From:\s*([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $fullEmail, $matches)) {
    $senderEmail = $matches[1];
}

// Extract plain text content
$plainText = '';
if (preg_match('/Content-Type: text\/plain.*?\n\n(.+?)\n\n--Apple-Mail/s', $fullEmail, $matches)) {
    $plainText = quoted_printable_decode($matches[1]);
} elseif (preg_match('/Content-Transfer-Encoding: quoted-printable\s*\n\s*\n(.+?)\n--/s', $fullEmail, $matches)) {
    $plainText = quoted_printable_decode($matches[1]);
}

echo "Sender Email: " . ($senderEmail ?: 'NOT FOUND') . "\n";
echo "Plain Text Length: " . strlen($plainText) . " chars\n\n";

if ($plainText) {
    $pipeline = app(HybridExtractionPipeline::class);
    $result = $pipeline->extract($plainText, 'email');
    $data = $result['data'] ?? [];
    
    // Enhance with header email if missing
    if (!data_get($data, 'contact.email') && $senderEmail) {
        data_set($data, 'contact.email', $senderEmail);
        echo "✅ Added email from headers: $senderEmail\n\n";
    }
    
    echo "=== COMPLETE QUOTATION MAPPING ===\n";
    
    // Map to quotation fields
    $quotationMapping = [
        'customer_name' => data_get($data, 'contact.name'),
        'customer_email' => data_get($data, 'contact.email'),
        'customer_phone' => data_get($data, 'contact.phone'),
        'customer_company' => data_get($data, 'contact.company'),
        
        // Routing
        'por' => data_get($data, 'shipment.origin'),
        'pol' => data_get($data, 'shipment.origin'),  // Often same as POR for car transport
        'pod' => data_get($data, 'shipment.destination'),
        
        // Cargo
        'cargo_description' => (data_get($data, 'vehicle.brand') ? data_get($data, 'vehicle.brand') . ' ' . data_get($data, 'vehicle.model') : null),
        'cargo_quantity' => 1,
        'cargo_unit' => 'x used vehicle',
        
        // Dimensions
        'cargo_length' => data_get($data, 'vehicle.dimensions.length_m'),
        'cargo_width' => data_get($data, 'vehicle.dimensions.width_m'),
        'cargo_height' => data_get($data, 'vehicle.dimensions.height_m'),
        'dim_before_delivery' => data_get($data, 'vehicle.dimensions.length_m') ? 
            data_get($data, 'vehicle.dimensions.length_m') . ' x ' . 
            data_get($data, 'vehicle.dimensions.width_m') . ' x ' . 
            data_get($data, 'vehicle.dimensions.height_m') . ' m' : null,
        
        // Transport
        'transport_mode' => data_get($data, 'shipment.shipping_type'),
        'service_type' => 'RoRo',
        
        // Quality metrics
        'extraction_quality' => round((data_get($data, 'final_validation.quality_score', 0) * 100), 1) . '%',
        'data_completeness' => round((data_get($data, 'final_validation.completeness_score', 0) * 100), 1) . '%',
        'needs_followup' => data_get($data, 'final_validation.completeness_score', 0) < 0.5 ? 'YES' : 'NO',
    ];
    
    echo "QUOTATION FIELDS:\n";
    foreach ($quotationMapping as $field => $value) {
        $status = $value ? '✅' : '❌';
        echo "$status $field: " . ($value ?: 'MISSING') . "\n";
    }
    
    echo "\n=== ROBAWS EXTRA FIELDS ===\n";
    $robawsFields = RobawsPayloadMapper::mapExtraFields($data);
    foreach ($robawsFields as $field => $value) {
        echo "✅ $field: $value\n";
    }
    
    echo "\n=== VALIDATION SUMMARY ===\n";
    $warnings = data_get($data, 'final_validation.warnings', []);
    $missingFields = array_keys(array_filter($quotationMapping, fn($v) => !$v));
    
    echo "Extraction Quality: " . $quotationMapping['extraction_quality'] . "\n";
    echo "Data Completeness: " . $quotationMapping['data_completeness'] . "\n";
    echo "Missing Fields: " . count($missingFields) . "\n";
    
    if (!empty($missingFields)) {
        echo "Missing: " . implode(', ', $missingFields) . "\n";
    }
    
    if (!empty($warnings)) {
        echo "Warnings:\n";
        foreach ($warnings as $warning) {
            echo "  ⚠️  $warning\n";
        }
    }
    
    echo "\n=== RECOMMENDATION ===\n";
    if (count($missingFields) <= 2 && data_get($data, 'final_validation.quality_score', 0) >= 0.8) {
        echo "✅ READY FOR QUOTATION - High quality extraction with minimal missing data\n";
    } elseif (count($missingFields) <= 4) {
        echo "⚠️  NEEDS MINOR FOLLOWUP - Good extraction but some fields missing\n";
    } else {
        echo "❌ NEEDS MAJOR FOLLOWUP - Too many missing fields for automatic processing\n";
    }
    
} else {
    echo "❌ Failed to extract email content\n";
}

<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use App\Services\Extraction\Strategies\EmailExtractionStrategy;

// Bootstrap Laravel
$app = new Application(realpath(__DIR__));
$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);
$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);
$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

echo "=== EMAIL EXTRACTION DATA MAPPING DEBUG ===\n\n";

// Find the BMW email file
$emailFiles = glob('*.eml');
if (empty($emailFiles)) {
    echo "❌ No .eml files found in current directory\n";
    exit(1);
}

$emailFile = $emailFiles[0]; // Use first available email
echo "📧 Using email file: $emailFile\n\n";

$content = file_get_contents($emailFile);
if (!$content) {
    echo "❌ Failed to read email file\n";
    exit(1);
}

echo "=== RUNNING EMAIL EXTRACTION ===\n";

try {
    $extractor = new EmailExtractionStrategy();
    $result = $extractor->extract($content);
    
    echo "✅ Extraction completed\n\n";
    
    echo "=== EXTRACTION RESULTS ===\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
    
    // Check for the specific data mapping issue
    $data = $result['data'] ?? [];
    
    echo "=== DATA MAPPING ANALYSIS ===\n";
    
    // Check shipment vs shipping inconsistency
    $shipmentOrigin = $data['shipment']['origin'] ?? null;
    $shipmentDestination = $data['shipment']['destination'] ?? null;
    $shippingOrigin = $data['shipping']['route']['origin']['city'] ?? null;
    $shippingDestination = $data['shipping']['route']['destination']['city'] ?? null;
    
    echo "Legacy shipment structure:\n";
    echo "  - Origin: " . ($shipmentOrigin ?: 'MISSING') . "\n";
    echo "  - Destination: " . ($shipmentDestination ?: 'MISSING') . "\n\n";
    
    echo "New shipping structure:\n";
    echo "  - Origin: " . ($shippingOrigin ?: 'MISSING') . "\n";
    echo "  - Destination: " . ($shippingDestination ?: 'MISSING') . "\n\n";
    
    // Check if contact name is incorrectly mapped to shipment
    $contactName = $data['contact']['name'] ?? null;
    if ($contactName) {
        $nameTokens = preg_split('/[\s\-]+/', strtolower($contactName));
        echo "Contact name: $contactName\n";
        echo "Name tokens: " . implode(', ', $nameTokens) . "\n\n";
        
        $shipmentMatchesContact = false;
        if ($shipmentOrigin && in_array(strtolower($shipmentOrigin), $nameTokens)) {
            echo "⚠️  ISSUE: Shipment origin '$shipmentOrigin' matches contact name token\n";
            $shipmentMatchesContact = true;
        }
        if ($shipmentDestination && in_array(strtolower($shipmentDestination), $nameTokens)) {
            echo "⚠️  ISSUE: Shipment destination '$shipmentDestination' matches contact name token\n";
            $shipmentMatchesContact = true;
        }
        
        if (!$shipmentMatchesContact) {
            echo "✅ Shipment origin/destination don't match contact name tokens\n";
        }
    }
    
    // Compare consistency
    if ($shipmentOrigin !== $shippingOrigin || $shipmentDestination !== $shippingDestination) {
        echo "\n❌ DATA MAPPING INCONSISTENCY DETECTED:\n";
        echo "   Legacy shipment: $shipmentOrigin → $shipmentDestination\n";
        echo "   New shipping: $shippingOrigin → $shippingDestination\n";
    } else {
        echo "\n✅ Data mapping is consistent between shipment and shipping sections\n";
    }
    
} catch (Exception $e) {
    echo "❌ Extraction failed: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\VehicleDatabase\VehicleDatabaseService;

// Bootstrap Laravel
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Testing New Oliver Email (English Version)\n";
echo "=" . str_repeat("=", 45) . "\n\n";

// New email content from the attachment
$emailBody = 'Hello,

We would like to ship our trailer (Suzuki Samurai plus RS-Camp caravan) with RoRO from Germany to Mombasa or Dar es Salaam. The trailer measures 800cm long, 204cm wide, 232cm high, and has a total weight of approximately 2000kg. Could you please provide us with a quote and tell us what documents we need for transport and entry into Kenya/Tanzania? What insurance do we need, and how do we handle any port and customs fees in Kenya/Tanzania?

Best regards, Oliver Sielemann';

// Initialize PatternExtractor
$vehicleDb = $app->make(VehicleDatabaseService::class);
$extractor = new PatternExtractor($vehicleDb);

echo "EMAIL CONTENT:\n";
echo str_repeat("-", 50) . "\n";
echo $emailBody . "\n\n";

// Extract data
echo "EXTRACTION RESULTS:\n";
echo str_repeat("-", 50) . "\n";

try {
    $extracted = $extractor->extract($emailBody);
    
    echo "VEHICLE DATA:\n";
    if (!empty($extracted['vehicle'])) {
        foreach ($extracted['vehicle'] as $key => $value) {
            echo "  $key: " . (is_array($value) ? json_encode($value) : $value) . "\n";
        }
    } else {
        echo "  No vehicle data extracted\n";
    }
    
    echo "\nSHIPMENT DATA:\n";
    if (!empty($extracted['shipment'])) {
        foreach ($extracted['shipment'] as $key => $value) {
            echo "  $key: " . (is_array($value) ? json_encode($value) : $value) . "\n";
        }
    } else {
        echo "  No shipment data extracted\n";
    }
    
    echo "\nFULL EXTRACTION DATA:\n";
    echo json_encode($extracted, JSON_PRETTY_PRINT) . "\n";
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "ISSUES TO FIX:\n";
    echo str_repeat("=", 60) . "\n";
    
    $issues = [
        'Vehicle Condition' => [
            'current' => $extracted['vehicle']['condition'] ?? 'not set',
            'expected' => 'used',
            'issue' => 'Should default to "used" for personal vehicle shipments'
        ],
        'Vehicle Description' => [
            'current' => ($extracted['vehicle']['brand'] ?? '') . ' ' . ($extracted['vehicle']['model'] ?? ''),
            'expected' => 'Suzuki Samurai connected to RS-Camp caravan',
            'issue' => 'Should show the trailer connection from parentheses'
        ],
        'Weight Issue' => [
            'current' => $extracted['vehicle']['weight_kg'] ?? 'not set',
            'expected' => '2000kg',
            'issue' => 'Email says "approximately 2000kg" but might extract something else'
        ]
    ];
    
    foreach ($issues as $issue => $data) {
        echo "\n$issue:\n";
        echo "  Current: {$data['current']}\n";
        echo "  Expected: {$data['expected']}\n";
        echo "  Issue: {$data['issue']}\n";
    }
    
} catch (Exception $e) {
    echo "Error during extraction: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "TEST COMPLETED\n";
echo str_repeat("=", 60) . "\n";

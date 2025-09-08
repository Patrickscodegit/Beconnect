<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\VehicleDatabase\VehicleDatabaseService;
use Illuminate\Support\Facades\Log;

// Bootstrap Laravel
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Testing Enhanced PatternExtractor with Oliver's Email\n";
echo "=" . str_repeat("=", 58) . "\n\n";

// Oliver's email content
$emailBody = 'Guten Tag,

wir haben ein Suzuki Samurai plus Anhänger zu verschiffen. Vor ca. 1,5 Jahren habe ich bereits eine Anfrage gestellt wegen diesem Fahrzeug, leider ist der Verkauf damals nicht zustande gekommen. Jetzt ist es soweit.

Die Daten:
Suzuki Samurai plus RS-Camp-Wohnwagenhänger, 1 Achse
800cm lang, 204cm breit, 232cm hoch
ca. 1,8t
ab Deutschland nach Mombasa oder Dar es Salaam

Können Sie mir ein Angebot machen?

Vielen Dank vorab
Oliver Sielemann';

// Initialize PatternExtractor with VehicleDatabaseService
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
    
    echo "\nALL EXTRACTION DATA:\n";
    echo json_encode($extracted, JSON_PRETTY_PRINT) . "\n";
    
    echo "\nDIMENSIONS DATA (from vehicle):\n";
    if (!empty($extracted['vehicle']['dimensions'])) {
        foreach ($extracted['vehicle']['dimensions'] as $key => $value) {
            echo "  $key: " . (is_array($value) ? json_encode($value) : $value) . "\n";
        }
    } else {
        echo "  No dimensions data extracted\n";
    }
    
    echo "\nCONTACT DATA:\n";
    if (!empty($extracted['contact'])) {
        foreach ($extracted['contact'] as $key => $value) {
            echo "  $key: " . (is_array($value) ? json_encode($value) : $value) . "\n";
        }
    } else {
        echo "  No contact data extracted\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "VALIDATION CHECKS:\n";
    echo str_repeat("=", 60) . "\n";
    
    // Check specific extractions we enhanced
    $checks = [
        'Vehicle Brand/Model' => [
            'expected' => 'Suzuki Samurai',
            'actual' => $extracted['vehicle']['brand'] ?? 'N/A' . ' ' . ($extracted['vehicle']['model'] ?? ''),
            'pattern' => 'Should extract "Suzuki Samurai" from parentheses'
        ],
        'Dimensions Length' => [
            'expected' => '8', // 800cm = 8m
            'actual' => $extracted['vehicle']['dimensions']['length_m'] ?? 'N/A',
            'pattern' => 'Should extract "8m" from "800cm lang"'
        ],
        'Dimensions Width' => [
            'expected' => '2.04', // 204cm = 2.04m
            'actual' => $extracted['vehicle']['dimensions']['width_m'] ?? 'N/A',
            'pattern' => 'Should extract "2.04m" from "204cm breit"'
        ],
        'Dimensions Height' => [
            'expected' => '2.32', // 232cm = 2.32m
            'actual' => $extracted['vehicle']['dimensions']['height_m'] ?? 'N/A',
            'pattern' => 'Should extract "2.32m" from "232cm hoch"'
        ],
        'Weight' => [
            'expected' => '1800', // 1.8t = 1800kg
            'actual' => $extracted['vehicle']['weight_kg'] ?? 'N/A',
            'pattern' => 'Should extract "1800" from "ca. 1,8t"'
        ],
        'Origin' => [
            'expected' => 'Germany/Deutschland',
            'actual' => $extracted['shipment']['origin'] ?? 'N/A',
            'pattern' => 'Should extract "Deutschland" as origin'
        ],
        'Destination' => [
            'expected' => 'Mombasa',
            'actual' => $extracted['shipment']['destination'] ?? 'N/A',
            'pattern' => 'Should extract "Mombasa" as primary destination'
        ],
        'Destination Options' => [
            'expected' => '["Mombasa", "Dar es Salaam"]',
            'actual' => json_encode($extracted['shipment']['destination_options'] ?? []),
            'pattern' => 'Should extract both destination options'
        ]
    ];
    
    foreach ($checks as $check => $data) {
        echo "\n$check:\n";
        echo "  Expected: {$data['expected']}\n";
        echo "  Actual: {$data['actual']}\n";
        echo "  Status: " . ($data['actual'] != 'N/A' && $data['actual'] != '[]' ? '✓ EXTRACTED' : '✗ MISSING') . "\n";
        echo "  Note: {$data['pattern']}\n";
    }
    
} catch (Exception $e) {
    echo "Error during extraction: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "TEST COMPLETED\n";
echo str_repeat("=", 60) . "\n";

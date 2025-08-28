<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Extraction\VehicleDataEnhancer;
use App\Services\VehicleDatabase\VehicleDatabaseService;
use App\Services\AiRouter;

// Test the exact problematic Alfa Giulietta case
$testData = [
    'vehicle' => [
        'make' => 'Alfa',
        'model' => 'Giulietta',
        'year' => '1960',
        'condition' => 'non-runner',
        'vin' => null,
        'engine_cc' => null,
        'fuel_type' => null,
        'color' => null,
        'dimensions' => [
            'length' => null,
            'width' => null,
            'height' => null,
            'unit' => 'm'
        ],
        'weight' => [
            'value' => null,
            'unit' => 'kg'
        ]
    ],
    'shipment' => [
        'origin' => 'Beverly Hills Car Club',
        'destination' => 'Antwerpen',
        'type' => 'LCL'
    ]
];

try {
    echo "Testing VehicleDataEnhancer with normalization fix for Alfa Giulietta 1960...\n\n";
    
    // Create the enhancer service
    $vehicleDb = app(VehicleDatabaseService::class);
    $aiRouter = app(AiRouter::class);
    $enhancer = new VehicleDataEnhancer($vehicleDb, $aiRouter);
    
    echo "Before enhancement:\n";
    echo "- Dimensions: " . json_encode($testData['vehicle']['dimensions']) . "\n";
    echo "- Weight: " . json_encode($testData['vehicle']['weight']) . "\n";
    echo "- Engine CC: " . var_export($testData['vehicle']['engine_cc'], true) . "\n";
    echo "- Fuel Type: " . var_export($testData['vehicle']['fuel_type'], true) . "\n\n";
    
    // Enhance the data
    $enhanced = $enhancer->enhance($testData, ['document_id' => 'test_normalization']);
    
    echo "After enhancement:\n";
    echo "=================\n";
    
    // Show enhanced vehicle data
    if (isset($enhanced['vehicle'])) {
        $vehicle = $enhanced['vehicle'];
        echo "Vehicle:\n";
        echo "- Make: " . ($vehicle['make'] ?? 'null') . "\n";
        echo "- Model: " . ($vehicle['model'] ?? 'null') . "\n";
        echo "- Year: " . ($vehicle['year'] ?? 'null') . "\n";
        echo "- Engine CC: " . ($vehicle['engine_cc'] ?? 'null') . "\n";
        echo "- Fuel Type: " . ($vehicle['fuel_type'] ?? 'null') . "\n";
        
        if (isset($vehicle['dimensions'])) {
            echo "- Dimensions:\n";
            echo "  - Length: " . ($vehicle['dimensions']['length'] ?? 'null') . " " . ($vehicle['dimensions']['unit'] ?? '') . "\n";
            echo "  - Width: " . ($vehicle['dimensions']['width'] ?? 'null') . " " . ($vehicle['dimensions']['unit'] ?? '') . "\n";
            echo "  - Height: " . ($vehicle['dimensions']['height'] ?? 'null') . " " . ($vehicle['dimensions']['unit'] ?? '') . "\n";
        }
        
        if (isset($vehicle['weight'])) {
            echo "- Weight: " . ($vehicle['weight']['value'] ?? 'null') . " " . ($vehicle['weight']['unit'] ?? '') . "\n";
        }
        
        if (isset($vehicle['cargo_volume_m3'])) {
            echo "- Cargo Volume: " . $vehicle['cargo_volume_m3'] . " m³\n";
        }
        
        if (isset($vehicle['typical_container'])) {
            echo "- Typical Container: " . $vehicle['typical_container'] . "\n";
        }
        
        if (isset($vehicle['shipping_notes'])) {
            echo "- Shipping Notes: " . $vehicle['shipping_notes'] . "\n";
        }
    }
    
    // Show enhancement metadata
    if (isset($enhanced['enhancement_metadata'])) {
        echo "\nEnhancement metadata:\n";
        echo "- Enhanced at: " . $enhanced['enhancement_metadata']['enhanced_at'] . "\n";
        echo "- Enhancement time: " . $enhanced['enhancement_metadata']['enhancement_time_ms'] . " ms\n";
        echo "- Sources used:\n";
        $sources = $enhanced['enhancement_metadata']['sources_used'];
        echo "  - Document: " . $sources['document'] . " fields\n";
        echo "  - Database: " . $sources['database'] . " fields\n";
        echo "  - AI: " . $sources['ai'] . " fields\n";
        echo "  - Calculated: " . $sources['calculated'] . " fields\n";
    }
    
    echo "\nTest completed successfully!\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Extraction\VehicleDataEnhancer;
use App\Services\VehicleDatabase\VehicleDatabaseService;
use App\Services\AiRouter;

// Test data matching the exact Alfa Giulietta structure
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
    echo "Testing VehicleDataEnhancer with Alfa Giulietta 1960 (exact reproduction)...\n\n";
    
    // Mock the AI response to see what the real AI returned
    $mockAiResponse = [
        'dimensions' => [
            'length_m' => 4.06,
            'width_m' => 1.56,
            'height_m' => 1.35
        ],
        'weight_kg' => 980,
        'cargo_volume_m3' => 0.4,
        'engine_cc' => 1300,
        'fuel_type' => 'petrol',
        'typical_container' => '20ft',
        'shipping_notes' => 'Classic car, requires careful handling'
    ];
    
    echo "Mock AI Response would be:\n";
    echo json_encode($mockAiResponse, JSON_PRETTY_PRINT) . "\n\n";
    
    // Test the individual conditions
    $vehicle = $testData['vehicle'];
    
    echo "Testing needsAiEnhancement conditions:\n";
    echo "=====================================\n";
    
    $hasDimensions = !empty($vehicle['dimensions']['length']) || 
                    !empty($vehicle['dimensions']['width']) || 
                    !empty($vehicle['dimensions']['height']);
    echo "Has dimensions: " . ($hasDimensions ? 'true' : 'false') . "\n";
    echo "- Length: " . var_export($vehicle['dimensions']['length'], true) . "\n";
    echo "- Width: " . var_export($vehicle['dimensions']['width'], true) . "\n";
    echo "- Height: " . var_export($vehicle['dimensions']['height'], true) . "\n";
    
    $hasWeight = !empty($vehicle['weight']['value']);
    echo "Has weight: " . ($hasWeight ? 'true' : 'false') . "\n";
    echo "- Value: " . var_export($vehicle['weight']['value'], true) . "\n";
    
    $hasVolume = !empty($vehicle['cargo_volume_m3']);
    echo "Has volume: " . ($hasVolume ? 'true' : 'false') . "\n";
    echo "- cargo_volume_m3: " . var_export($vehicle['cargo_volume_m3'] ?? 'not set', true) . "\n";
    
    $needsEnhancement = !$hasDimensions || !$hasWeight || !$hasVolume;
    echo "Needs enhancement: " . ($needsEnhancement ? 'true' : 'false') . "\n\n";
    
    // Test the merging conditions manually
    echo "Testing AI field merging conditions:\n";
    echo "====================================\n";
    
    // Test dimensions
    if (!empty($mockAiResponse['dimensions'])) {
        echo "AI provided dimensions: YES\n";
        
        if (!empty($mockAiResponse['dimensions']['length_m']) && empty($vehicle['dimensions']['length'])) {
            echo "✓ Length would be merged: " . $mockAiResponse['dimensions']['length_m'] . " m\n";
        } else {
            echo "✗ Length merge condition failed\n";
            echo "  - AI has length_m: " . var_export(!empty($mockAiResponse['dimensions']['length_m']), true) . "\n";
            echo "  - Vehicle missing length: " . var_export(empty($vehicle['dimensions']['length']), true) . "\n";
        }
        
        if (!empty($mockAiResponse['dimensions']['width_m']) && empty($vehicle['dimensions']['width'])) {
            echo "✓ Width would be merged: " . $mockAiResponse['dimensions']['width_m'] . " m\n";
        } else {
            echo "✗ Width merge condition failed\n";
        }
        
        if (!empty($mockAiResponse['dimensions']['height_m']) && empty($vehicle['dimensions']['height'])) {
            echo "✓ Height would be merged: " . $mockAiResponse['dimensions']['height_m'] . " m\n";
        } else {
            echo "✗ Height merge condition failed\n";
        }
    }
    
    // Test weight
    if (!empty($mockAiResponse['weight_kg']) && (empty($vehicle['weight']['value']) || $vehicle['weight']['value'] === null)) {
        echo "✓ Weight would be merged: " . $mockAiResponse['weight_kg'] . " kg\n";
    } else {
        echo "✗ Weight merge condition failed\n";
        echo "  - AI has weight_kg: " . var_export(!empty($mockAiResponse['weight_kg']), true) . "\n";
        echo "  - Vehicle weight value: " . var_export($vehicle['weight']['value'], true) . "\n";
        echo "  - Empty check: " . var_export(empty($vehicle['weight']['value']), true) . "\n";
        echo "  - Null check: " . var_export($vehicle['weight']['value'] === null, true) . "\n";
    }
    
    // Test other fields
    if (!empty($mockAiResponse['engine_cc']) && empty($vehicle['engine_cc'])) {
        echo "✓ Engine CC would be merged: " . $mockAiResponse['engine_cc'] . " cc\n";
    } else {
        echo "✗ Engine CC merge condition failed\n";
    }
    
    if (!empty($mockAiResponse['fuel_type']) && empty($vehicle['fuel_type'])) {
        echo "✓ Fuel type would be merged: " . $mockAiResponse['fuel_type'] . "\n";
    } else {
        echo "✗ Fuel type merge condition failed\n";
    }
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

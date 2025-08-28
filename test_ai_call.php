<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\AiRouter;

try {
    echo "Testing actual AI call for Alfa Giulietta 1960...\n\n";
    
    $aiRouter = app(AiRouter::class);
    
    // Build the same prompt that would be used
    $vehicleDescription = "1960 Alfa Giulietta (non-runner)";
    
    $prompt = "Provide accurate technical specifications for: {$vehicleDescription}\n\n";
    $prompt .= "Current known specifications:\n";
    $prompt .= "- Make: Alfa\n";
    $prompt .= "- Model: Giulietta\n";
    $prompt .= "- Year: 1960\n";
    $prompt .= "- Condition: non-runner\n";
    $prompt .= "\nThis is a classic/vintage vehicle. Use historical manufacturer specifications.\n";
    $prompt .= "\nProvide the following manufacturer specifications using these exact field names:\n";
    $prompt .= "- Dimensions (use fields: length_m, width_m, height_m in meters)\n";
    $prompt .= "- Weight (use field: weight_kg in kilograms)\n";
    $prompt .= "- Cargo volume (use field: cargo_volume_m3 in cubic meters)\n";
    $prompt .= "- Engine displacement (use field: engine_cc in cubic centimeters)\n";
    $prompt .= "- Fuel type (use field: fuel_type - petrol, diesel, electric, hybrid)\n";
    $prompt .= "- Typical shipping container (use field: typical_container - 20ft, 40ft, RoRo, etc.)\n";
    $prompt .= "- Special shipping notes (use field: shipping_notes)\n\n";
    $prompt .= "Return only factual manufacturer specifications. Be precise and accurate.\n";
    $prompt .= "Use the exact field names specified above.\n";
    $prompt .= "For classic/vintage vehicles, provide historical manufacturer data.";
    
    echo "Prompt being sent to AI:\n";
    echo "========================\n";
    echo $prompt . "\n\n";
    
    // Call AI with the exact same schema
    $schema = [
        'type' => 'object',
        'properties' => [
            'dimensions' => [
                'type' => 'object',
                'properties' => [
                    'length_m' => ['type' => 'number'],
                    'width_m' => ['type' => 'number'],
                    'height_m' => ['type' => 'number']
                ]
            ],
            'weight_kg' => ['type' => 'number'],
            'cargo_volume_m3' => ['type' => 'number'],
            'engine_cc' => ['type' => 'number'],
            'fuel_type' => ['type' => 'string'],
            'typical_container' => ['type' => 'string'],
            'shipping_notes' => ['type' => 'string']
        ]
    ];
    
    echo "Schema being used:\n";
    echo "==================\n";
    echo json_encode($schema, JSON_PRETTY_PRINT) . "\n\n";
    
    $options = [
        'service' => 'openai',
        'temperature' => 0.1
    ];
    
    echo "Calling AI...\n";
    $aiSpecs = $aiRouter->extract($prompt, $schema, $options);
    
    echo "AI Response:\n";
    echo "============\n";
    echo json_encode($aiSpecs, JSON_PRETTY_PRINT) . "\n\n";
    
    echo "AI Response field analysis:\n";
    echo "===========================\n";
    echo "Top-level keys: " . implode(', ', array_keys($aiSpecs)) . "\n";
    
    foreach ($aiSpecs as $key => $value) {
        echo "- {$key}: " . var_export($value, true) . "\n";
    }
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

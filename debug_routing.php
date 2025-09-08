<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Export\Mappers\RobawsMapper;
use App\Models\Intake;

echo "\n=== Debug Routing Logic ===\n";

$testExtractionData = [
    'origin' => 'Germany',
    'destination' => 'Belgium',
    // Remove door-to-door keywords to test port-to-port logic
    'vehicles' => [[
        'brand' => 'Suzuki',
        'model' => 'Samurai',
        'full_description' => '1 x used Suzuki Samurai connected to 1 x used RS-Camp caravan'
    ]],
    'shipping' => [
        'route' => [
            'origin' => ['location' => 'Germany'],
            'destination' => ['location' => 'Belgium']
        ]
    ],
    'shipment' => [
        'origin' => 'Germany',
        'destination' => 'Belgium'
    ]
];

$intake = new Intake();
$intake->id = 12345;

// Create reflection to access private methods
$mapper = new RobawsMapper();
$reflection = new \ReflectionClass($mapper);

// Test isPortDestination
$isPortMethod = $reflection->getMethod('isPortDestination');
$isPortMethod->setAccessible(true);

echo "Germany is port: " . ($isPortMethod->invoke($mapper, 'Germany') ? 'true' : 'false') . "\n";
echo "Belgium is port: " . ($isPortMethod->invoke($mapper, 'Belgium') ? 'true' : 'false') . "\n";

// Test isPortToPortShipping
$isPortToPortMethod = $reflection->getMethod('isPortToPortShipping');
$isPortToPortMethod->setAccessible(true);

echo "Is port-to-port: " . ($isPortToPortMethod->invoke($mapper, 'Germany', 'Belgium', $testExtractionData, []) ? 'true' : 'false') . "\n";

// Test getDefaultPortForLocation
$getPortMethod = $reflection->getMethod('getDefaultPortForLocation');
$getPortMethod->setAccessible(true);

echo "Default port for Germany: " . $getPortMethod->invoke($mapper, 'Germany') . "\n";
echo "Default port for Belgium: " . $getPortMethod->invoke($mapper, 'Belgium') . "\n";

// Test the full mapping
echo "\n=== Full Routing Result ===\n";
$result = $mapper->mapIntakeToRobaws($intake, $testExtractionData);
$routing = $result['routing'] ?? [];

echo "POR: " . ($routing['por'] ?? 'N/A') . "\n";
echo "POL: " . ($routing['pol'] ?? 'N/A') . "\n";
echo "POD: " . ($routing['pod'] ?? 'N/A') . "\n";  
echo "FDEST: " . ($routing['fdest'] ?? 'N/A') . "\n";

echo "\n=== Debug Complete ===\n";

<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\VehicleDatabase\VehicleDatabaseService;

// Bootstrap Laravel
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$content = 'ab Deutschland nach Mombasa oder Dar es Salaam';

echo "Testing extractShippingData with: '$content'\n\n";

// Initialize PatternExtractor
$vehicleDb = $app->make(VehicleDatabaseService::class);
$extractor = new PatternExtractor($vehicleDb);

// Use reflection to access the private method
$reflection = new ReflectionClass($extractor);
$method = $reflection->getMethod('extractShippingData');
$method->setAccessible(true);

$shipping = $method->invoke($extractor, $content);

echo "Extracted shipping data:\n";
print_r($shipping);

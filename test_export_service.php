<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Robaws\RobawsExportService;

echo "Testing RobawsExportService method call handling...\n\n";

try {
    $service = app(RobawsExportService::class);
    echo "✅ RobawsExportService instantiated successfully\n";
    
    // Check if the service has the apiClient property
    $reflection = new ReflectionClass($service);
    if ($reflection->hasProperty('apiClient')) {
        echo "✅ apiClient property exists\n";
        
        $property = $reflection->getProperty('apiClient');
        $property->setAccessible(true);
        $apiClient = $property->getValue($service);
        
        echo "API Client class: " . get_class($apiClient) . "\n";
        echo "setOfferContact method exists: " . (method_exists($apiClient, 'setOfferContact') ? 'YES' : 'NO') . "\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\Export\Clients\RobawsApiClient;

echo "Testing setOfferContact method existence...\n\n";

try {
    $client = new RobawsApiClient();
    
    echo "✅ RobawsApiClient instantiated successfully\n";
    
    if (method_exists($client, 'setOfferContact')) {
        echo "✅ setOfferContact method exists\n";
        
        // Get method info
        $reflection = new ReflectionMethod($client, 'setOfferContact');
        echo "Method signature: " . $reflection->getName() . "(";
        $params = $reflection->getParameters();
        $paramStrings = [];
        foreach ($params as $param) {
            $type = $param->getType() ? $param->getType()->getName() : 'mixed';
            $paramStrings[] = $type . ' $' . $param->getName();
        }
        echo implode(', ', $paramStrings) . ")\n";
        
    } else {
        echo "❌ setOfferContact method does NOT exist\n";
    }
    
    // Check for any methods with similar names
    $methods = get_class_methods($client);
    $similarMethods = array_filter($methods, function($method) {
        return stripos($method, 'offer') !== false && stripos($method, 'contact') !== false;
    });
    
    if (!empty($similarMethods)) {
        echo "\nSimilar methods found:\n";
        foreach ($similarMethods as $method) {
            echo "- $method\n";
        }
    }
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

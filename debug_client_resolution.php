<?php

// Debug script to test client resolution
require_once 'vendor/autoload.php';
require_once 'bootstrap/app.php';

use App\Services\Robaws\ClientResolver;
use App\Services\Export\Clients\RobawsApiClient;

echo "🔍 DEBUGGING CLIENT RESOLUTION\n";
echo "===============================\n\n";

try {
    // Test the RobawsApiClient methods exist
    $apiClient = new RobawsApiClient();
    
    echo "1. Checking API Client Methods:\n";
    echo "   - findClientByEmail exists: " . (method_exists($apiClient, 'findClientByEmail') ? '✅ YES' : '❌ NO') . "\n";
    echo "   - findContactByEmail exists: " . (method_exists($apiClient, 'findContactByEmail') ? '✅ YES' : '❌ NO') . "\n";
    echo "   - findClientByPhone exists: " . (method_exists($apiClient, 'findClientByPhone') ? '✅ YES' : '❌ NO') . "\n";
    echo "   - getClientById exists: " . (method_exists($apiClient, 'getClientById') ? '✅ YES' : '❌ NO') . "\n";
    echo "   - listClients exists: " . (method_exists($apiClient, 'listClients') ? '✅ YES' : '❌ NO') . "\n";
    echo "   - createClient exists: " . (method_exists($apiClient, 'createClient') ? '✅ YES' : '❌ NO') . "\n\n";

    // Test client resolver
    echo "2. Testing ClientResolver:\n";
    
    // Test with sample data (like BMW email case)
    $resolver = new ClientResolver($apiClient);
    $testHints = [
        'email' => 'badr.algothami@gmail.com',
        'name' => 'Badr Algothami'
    ];
    
    echo "   Testing with hints: " . json_encode($testHints) . "\n";
    
    try {
        $result = $resolver->resolve($testHints);
        echo "   Resolution result: " . ($result ? json_encode($result) : 'null') . "\n";
    } catch (\Exception $e) {
        echo "   ❌ Resolution error: " . $e->getMessage() . "\n";
        echo "   Error trace: " . $e->getTraceAsString() . "\n";
    }

    echo "\n3. Testing Individual Method Calls:\n";
    
    // Test findClientByEmail directly
    echo "   Testing findClientByEmail('badr.algothami@gmail.com')...\n";
    try {
        $emailResult = $apiClient->findClientByEmail('badr.algothami@gmail.com');
        echo "   Result: " . ($emailResult ? json_encode($emailResult) : 'null') . "\n";
    } catch (\Exception $e) {
        echo "   ❌ Email search error: " . $e->getMessage() . "\n";
    }
    
    echo "\n4. Configuration Check:\n";
    $config = $apiClient->validateConfig();
    echo "   Configuration valid: " . ($config['valid'] ? '✅ YES' : '❌ NO') . "\n";
    if (!$config['valid']) {
        echo "   Issues: " . implode(', ', $config['issues']) . "\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n🏁 Debug complete\n";

?>

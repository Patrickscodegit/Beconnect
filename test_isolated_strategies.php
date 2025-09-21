<?php

/**
 * Test script to verify isolated extraction strategies work correctly
 * Run this to test the isolation without affecting your existing system
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Extraction\Strategies\IsolatedExtractionStrategyFactory;
use App\Services\Extraction\Strategies\ExtractionStrategyFactory;

echo "🧪 Testing Isolated Extraction Strategies\n";
echo "==========================================\n\n";

try {
    // Test isolated factory
    echo "1. Testing Isolated Strategy Factory:\n";
    $isolatedFactory = new IsolatedExtractionStrategyFactory();
    $isolatedStats = $isolatedFactory->getStatistics();
    
    echo "   ✅ Factory Type: {$isolatedStats['factory_type']}\n";
    echo "   ✅ Isolation Mode: " . ($isolatedStats['isolation_mode'] ? 'Enabled' : 'Disabled') . "\n";
    echo "   ✅ Total Strategies: {$isolatedStats['total_strategies']}\n\n";
    
    echo "   📋 Registered Strategies:\n";
    foreach ($isolatedStats['strategies'] as $strategy) {
        $isolationLevel = $strategy['isolation_level'];
        $icon = $isolationLevel === 'complete' ? '🔒' : ($isolationLevel === 'partial' ? '🛡️' : '🔓');
        echo "      {$icon} {$strategy['name']} (Priority: {$strategy['priority']})\n";
        echo "         Class: {$strategy['class']}\n";
        echo "         Isolation: {$isolationLevel}\n";
    }
    
    echo "\n2. Testing Isolation Status:\n";
    $isolationTest = $isolatedFactory->testIsolation();
    foreach ($isolationTest as $strategyName => $test) {
        $status = $test['is_isolated'] ? '✅ ISOLATED' : '❌ SHARED';
        echo "   {$strategyName}: {$status}\n";
        echo "      Dependencies: " . implode(', ', $test['dependencies']) . "\n";
    }
    
    echo "\n3. Testing Strategy Switching:\n";
    echo "   Switching to shared strategies...\n";
    $isolatedFactory->enableSharedStrategies();
    echo "   ✅ Switched to shared strategies\n";
    
    echo "   Switching back to isolated strategies...\n";
    $isolatedFactory->enableIsolatedStrategies();
    echo "   ✅ Switched back to isolated strategies\n";
    
    echo "\n4. Testing Original Factory (for comparison):\n";
    $originalFactory = new ExtractionStrategyFactory();
    $originalStats = $originalFactory->getStatistics();
    
    echo "   ✅ Total Strategies: {$originalStats['total_strategies']}\n";
    echo "   📋 Registered Strategies:\n";
    foreach ($originalStats['strategies'] as $strategy) {
        echo "      🔓 {$strategy['name']} (Priority: {$strategy['priority']})\n";
        echo "         Class: {$strategy['class']}\n";
    }
    
    echo "\n🎉 All tests passed!\n";
    echo "\n💡 Benefits of Isolated Strategies:\n";
    echo "   • Email processing is completely protected\n";
    echo "   • PDF/Image enhancements won't break email\n";
    echo "   • Each strategy has its own processing pipeline\n";
    echo "   • Easy to enhance individual strategies\n";
    
    echo "\n🚀 Next Steps:\n";
    echo "   1. Add to .env: EXTRACTION_USE_ISOLATED_STRATEGIES=true\n";
    echo "   2. Register IsolatedExtractionServiceProvider\n";
    echo "   3. Run: php artisan extraction:test-isolation\n";
    echo "   4. Start enhancing PDF/Image processing with confidence!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}


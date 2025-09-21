<?php

namespace App\Console\Commands;

use App\Services\Extraction\Strategies\IsolatedExtractionStrategyFactory;
use App\Services\Extraction\Strategies\ExtractionStrategyFactory;
use Illuminate\Console\Command;

class TestExtractionIsolation extends Command
{
    protected $signature = 'extraction:test-isolation {--switch= : Switch to isolated or shared strategies}';
    protected $description = 'Test extraction strategy isolation and switch between modes';

    public function handle(): int
    {
        $this->info('🔍 Testing Extraction Strategy Isolation');
        $this->newLine();

        // Check current configuration
        $useIsolated = config('extraction.use_isolated_strategies', true);
        $strategyMode = config('extraction.strategy_mode', 'isolated');

        $this->info("Current Configuration:");
        $this->line("  Strategy Mode: {$strategyMode}");
        $this->line("  Use Isolated: " . ($useIsolated ? 'Yes' : 'No'));
        $this->newLine();

        // Handle switch command
        if ($switch = $this->option('switch')) {
            return $this->switchStrategies($switch);
        }

        // Test current strategies
        $this->testCurrentStrategies();

        return self::SUCCESS;
    }

    private function testCurrentStrategies(): void
    {
        $this->info('🧪 Testing Current Strategies');
        
        try {
            $factory = app(ExtractionStrategyFactory::class);
            
            if ($factory instanceof IsolatedExtractionStrategyFactory) {
                $this->testIsolatedFactory($factory);
            } else {
                $this->testSharedFactory($factory);
            }
            
        } catch (\Exception $e) {
            $this->error("Error testing strategies: " . $e->getMessage());
        }
    }

    private function testIsolatedFactory(IsolatedExtractionStrategyFactory $factory): void
    {
        $this->info('✅ Using ISOLATED Strategy Factory');
        
        $statistics = $factory->getStatistics();
        $this->line("  Factory Type: {$statistics['factory_type']}");
        $this->line("  Isolation Mode: " . ($statistics['isolation_mode'] ? 'Enabled' : 'Disabled'));
        $this->line("  Total Strategies: {$statistics['total_strategies']}");
        
        $this->newLine();
        $this->info('📋 Registered Strategies:');
        
        foreach ($statistics['strategies'] as $strategy) {
            $isolationLevel = $strategy['isolation_level'];
            $icon = $isolationLevel === 'complete' ? '🔒' : ($isolationLevel === 'partial' ? '🛡️' : '🔓');
            
            $this->line("  {$icon} {$strategy['name']} (Priority: {$strategy['priority']})");
            $this->line("     Class: {$strategy['class']}");
            $this->line("     Isolation: {$isolationLevel}");
        }
        
        $this->newLine();
        $this->info('🧪 Isolation Test Results:');
        
        $isolationTest = $factory->testIsolation();
        foreach ($isolationTest as $strategyName => $test) {
            $status = $test['is_isolated'] ? '✅ ISOLATED' : '❌ SHARED';
            $this->line("  {$strategyName}: {$status}");
            $this->line("     Dependencies: " . implode(', ', $test['dependencies']));
        }
        
        $this->newLine();
        $this->info('💡 Benefits of Isolated Strategies:');
        $this->line('  • Email processing is completely protected');
        $this->line('  • PDF/Image enhancements won\'t break email');
        $this->line('  • Each strategy has its own processing pipeline');
        $this->line('  • Easy to enhance individual strategies');
    }

    private function testSharedFactory(ExtractionStrategyFactory $factory): void
    {
        $this->info('⚠️  Using SHARED Strategy Factory');
        
        $statistics = $factory->getStatistics();
        $this->line("  Total Strategies: {$statistics['total_strategies']}");
        
        $this->newLine();
        $this->info('📋 Registered Strategies:');
        
        foreach ($statistics['strategies'] as $strategy) {
            $this->line("  🔓 {$strategy['name']} (Priority: {$strategy['priority']})");
            $this->line("     Class: {$strategy['class']}");
        }
        
        $this->newLine();
        $this->warn('⚠️  Risks of Shared Strategies:');
        $this->line('  • Changes to one strategy can break others');
        $this->line('  • Email processing may be affected by PDF/Image changes');
        $this->line('  • Shared dependencies can cause conflicts');
        $this->line('  • Harder to enhance individual strategies');
        
        $this->newLine();
        $this->info('💡 Recommendation: Switch to isolated strategies');
        $this->line('  Run: php artisan extraction:test-isolation --switch=isolated');
    }

    private function switchStrategies(string $mode): int
    {
        if (!in_array($mode, ['isolated', 'shared'])) {
            $this->error('Invalid mode. Use "isolated" or "shared"');
            return self::FAILURE;
        }

        $this->info("🔄 Switching to {$mode} strategies...");

        try {
            $factory = app(IsolatedExtractionStrategyFactory::class);
            
            if ($mode === 'isolated') {
                $factory->enableIsolatedStrategies();
                $this->info('✅ Switched to ISOLATED strategies');
                $this->line('  • Email processing is now protected');
                $this->line('  • PDF/Image enhancements won\'t break email');
                $this->line('  • Each strategy is completely isolated');
            } else {
                $factory->enableSharedStrategies();
                $this->warn('⚠️  Switched to SHARED strategies');
                $this->line('  • All strategies share dependencies');
                $this->line('  • Changes to one strategy may affect others');
                $this->line('  • Email processing may be affected by other changes');
            }
            
            $this->newLine();
            $this->info('📝 To make this change permanent, update your .env file:');
            $this->line("  EXTRACTION_USE_ISOLATED_STRATEGIES=" . ($mode === 'isolated' ? 'true' : 'false'));
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Error switching strategies: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}


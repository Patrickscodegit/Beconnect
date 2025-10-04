<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ISOLATED EXTRACTION STRATEGY FACTORY
 * 
 * This factory allows you to switch between isolated and shared strategies
 * without breaking existing functionality. You can enhance PDF/Image processing
 * while keeping email processing completely isolated.
 */
class IsolatedExtractionStrategyFactory implements ExtractionStrategyFactoryInterface
{
    private Collection $strategies;
    private bool $useIsolatedStrategies;

    public function __construct()
    {
        $this->strategies = collect();
        $this->useIsolatedStrategies = config('extraction.use_isolated_strategies', true);
        $this->registerStrategies();
    }

    /**
     * Register strategies based on configuration
     */
    private function registerStrategies(): void
    {
        if ($this->useIsolatedStrategies) {
            $this->registerIsolatedStrategies();
        } else {
            $this->registerSharedStrategies();
        }
    }

    /**
     * Register isolated strategies (recommended for stability)
     */
    private function registerIsolatedStrategies(): void
    {
        // Register isolated email strategy
        $this->register(app(IsolatedEmailExtractionStrategy::class));

        // Register optimized PDF strategy (Phase 1 optimizations)
        $this->register(app(OptimizedPdfExtractionStrategy::class));

        // Register simple PDF strategy (fallback)
        $this->register(app(SimplePdfExtractionStrategy::class));

        // Register enhanced image strategy
        $this->register(app(EnhancedImageExtractionStrategy::class));
    }

    /**
     * Register shared strategies (legacy mode)
     */
    private function registerSharedStrategies(): void
    {
        // Register original strategies
        $this->register(app(EmailExtractionStrategy::class));
        $this->register(app(SimplePdfExtractionStrategy::class)); // Use simple strategy in legacy mode too
        $this->register(app(ImageExtractionStrategy::class));
    }

    /**
     * Register a strategy with the factory
     */
    public function register(ExtractionStrategy $strategy): self
    {
        $this->strategies->push($strategy);
        
        Log::info('Strategy registered', [
            'strategy' => $strategy->getName(),
            'priority' => $strategy->getPriority(),
            'class' => get_class($strategy),
            'isolation_mode' => $this->useIsolatedStrategies
        ]);
        
        return $this;
    }

    /**
     * Get the best strategy for a document
     */
    public function getStrategy(Document $document): ?ExtractionStrategy
    {
        $supportedStrategies = $this->strategies
            ->filter(fn(ExtractionStrategy $strategy) => $strategy->supports($document))
            ->sortByDesc(fn(ExtractionStrategy $strategy) => $strategy->getPriority());

        if ($supportedStrategies->isEmpty()) {
            Log::warning('No extraction strategy found for document', [
                'document_id' => $document->id,
                'mime_type' => $document->mime_type,
                'filename' => $document->filename,
                'isolation_mode' => $this->useIsolatedStrategies
            ]);
            return null;
        }

        $selectedStrategy = $supportedStrategies->first();

        Log::info('Strategy selected for document', [
            'document_id' => $document->id,
            'strategy' => $selectedStrategy->getName(),
            'priority' => $selectedStrategy->getPriority(),
            'available_strategies' => $supportedStrategies->map(fn($s) => $s->getName())->toArray(),
            'isolation_mode' => $this->useIsolatedStrategies,
            'strategy_class' => get_class($selectedStrategy)
        ]);

        return $selectedStrategy;
    }

    /**
     * Get all strategies that support a document
     */
    public function getSupportedStrategies(Document $document): Collection
    {
        return $this->strategies
            ->filter(fn(ExtractionStrategy $strategy) => $strategy->supports($document))
            ->sortByDesc(fn(ExtractionStrategy $strategy) => $strategy->getPriority())
            ->values();
    }

    /**
     * Get all registered strategies
     */
    public function getAllStrategies(): Collection
    {
        return $this->strategies->sortByDesc(fn(ExtractionStrategy $strategy) => $strategy->getPriority());
    }

    /**
     * Get strategy by name
     */
    public function getStrategyByName(string $name): ?ExtractionStrategy
    {
        return $this->strategies
            ->first(fn(ExtractionStrategy $strategy) => $strategy->getName() === $name);
    }

    /**
     * Switch to isolated strategies (recommended)
     */
    public function enableIsolatedStrategies(): self
    {
        $this->useIsolatedStrategies = true;
        $this->strategies = collect();
        $this->registerIsolatedStrategies();
        
        Log::info('Switched to ISOLATED strategies', [
            'factory' => 'IsolatedExtractionStrategyFactory',
            'isolation_level' => 'complete'
        ]);
        
        return $this;
    }

    /**
     * Switch to shared strategies (legacy mode)
     */
    public function enableSharedStrategies(): self
    {
        $this->useIsolatedStrategies = false;
        $this->strategies = collect();
        $this->registerSharedStrategies();
        
        Log::info('Switched to SHARED strategies', [
            'factory' => 'IsolatedExtractionStrategyFactory',
            'isolation_level' => 'none'
        ]);
        
        return $this;
    }

    /**
     * Check if using isolated strategies
     */
    public function isUsingIsolatedStrategies(): bool
    {
        return $this->useIsolatedStrategies;
    }

    /**
     * Clear all strategies (useful for testing)
     */
    public function clear(): self
    {
        $this->strategies = collect();
        return $this;
    }

    /**
     * Get statistics about strategies
     */
    public function getStatistics(): array
    {
        return [
            'factory_type' => 'IsolatedExtractionStrategyFactory',
            'isolation_mode' => $this->useIsolatedStrategies,
            'total_strategies' => $this->strategies->count(),
            'strategies' => $this->strategies->map(function (ExtractionStrategy $strategy) {
                return [
                    'name' => $strategy->getName(),
                    'priority' => $strategy->getPriority(),
                    'class' => get_class($strategy),
                    'isolation_level' => $this->getIsolationLevel($strategy)
                ];
            })->toArray()
        ];
    }

    /**
     * Get isolation level for a strategy
     */
    private function getIsolationLevel(ExtractionStrategy $strategy): string
    {
        $className = get_class($strategy);
        
        if (str_contains($className, 'Isolated')) {
            return 'complete';
        } elseif (str_contains($className, 'Enhanced')) {
            return 'partial';
        } else {
            return 'none';
        }
    }

    /**
     * Test strategy isolation
     */
    public function testIsolation(): array
    {
        $results = [];
        
        foreach ($this->strategies as $strategy) {
            $results[$strategy->getName()] = [
                'class' => get_class($strategy),
                'isolation_level' => $this->getIsolationLevel($strategy),
                'dependencies' => $this->getStrategyDependencies($strategy),
                'is_isolated' => $this->isStrategyIsolated($strategy)
            ];
        }
        
        return $results;
    }

    /**
     * Get strategy dependencies
     */
    private function getStrategyDependencies(ExtractionStrategy $strategy): array
    {
        $reflection = new \ReflectionClass($strategy);
        $constructor = $reflection->getConstructor();
        
        if (!$constructor) {
            return [];
        }
        
        $dependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type && !$type->isBuiltin()) {
                $dependencies[] = $type->getName();
            }
        }
        
        return $dependencies;
    }

    /**
     * Check if strategy is isolated
     */
    private function isStrategyIsolated(ExtractionStrategy $strategy): bool
    {
        $className = get_class($strategy);
        return str_contains($className, 'Isolated') || str_contains($className, 'Enhanced');
    }
}

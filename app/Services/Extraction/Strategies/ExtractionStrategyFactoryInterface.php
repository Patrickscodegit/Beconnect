<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use Illuminate\Support\Collection;

/**
 * Interface for extraction strategy factories
 * 
 * This interface allows both the original ExtractionStrategyFactory
 * and the new IsolatedExtractionStrategyFactory to be used interchangeably
 * in the ExtractionPipeline, solving the type mismatch issue.
 */
interface ExtractionStrategyFactoryInterface
{
    /**
     * Register a strategy with the factory
     */
    public function register(ExtractionStrategy $strategy): self;

    /**
     * Get the best strategy for a document
     */
    public function getStrategy(Document $document): ?ExtractionStrategy;

    /**
     * Get all strategies that support a document
     */
    public function getSupportedStrategies(Document $document): Collection;

    /**
     * Get all registered strategies
     */
    public function getAllStrategies(): Collection;

    /**
     * Get strategy by name
     */
    public function getStrategyByName(string $name): ?ExtractionStrategy;

    /**
     * Get statistics about strategies
     */
    public function getStatistics(): array;
}


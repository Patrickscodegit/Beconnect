<?php

namespace App\Services\Extraction\Strategies;

use App\Models\Document;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ExtractionStrategyFactory
{
    private Collection $strategies;

    public function __construct()
    {
        $this->strategies = collect();
        $this->registerDefaultStrategies();
    }

    /**
     * Register a strategy with the factory
     */
    public function register(ExtractionStrategy $strategy): self
    {
        $this->strategies->push($strategy);
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
                'filename' => $document->filename
            ]);
            return null;
        }

        $selectedStrategy = $supportedStrategies->first();

        Log::info('Strategy selected for document', [
            'document_id' => $document->id,
            'strategy' => $selectedStrategy->getName(),
            'priority' => $selectedStrategy->getPriority(),
            'available_strategies' => $supportedStrategies->map(fn($s) => $s->getName())->toArray()
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
     * Register default strategies
     */
    private function registerDefaultStrategies(): void
    {
        // Register email strategy
        $this->register(app(EmailExtractionStrategy::class));

        // Additional strategies can be registered here
        // $this->register(app(PdfExtractionStrategy::class));
        // $this->register(app(ImageExtractionStrategy::class));
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
            'total_strategies' => $this->strategies->count(),
            'strategies' => $this->strategies->map(function (ExtractionStrategy $strategy) {
                return [
                    'name' => $strategy->getName(),
                    'priority' => $strategy->getPriority(),
                    'class' => get_class($strategy)
                ];
            })->toArray()
        ];
    }
}

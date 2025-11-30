<?php

namespace App\Services\Quotation;

use App\Models\QuotationRequestArticle;
use App\Services\Quotation\Calculators\LmQuantityCalculator;
use App\Services\Quotation\Calculators\CbmQuantityCalculator;
use App\Services\Quotation\Calculators\DefaultQuantityCalculator;
use App\Services\Quotation\Calculators\QuantityCalculatorInterface;

class QuantityCalculationService
{
    protected array $calculators = [];

    public function __construct()
    {
        // Register calculators for different unit types
        $this->calculators = [
            'LM' => LmQuantityCalculator::class,
            'CBM' => CbmQuantityCalculator::class,
            // Future calculators can be added here:
            // 'OVERHEIGHT' => OverheightQuantityCalculator::class,
            // 'OVERWEIGHT' => OverweightQuantityCalculator::class,
        ];
    }

    /**
     * Calculate effective quantity for an article based on its unit type
     * 
     * @param QuotationRequestArticle $article
     * @return float
     */
    public function calculateQuantity(QuotationRequestArticle $article): float
    {
        $unitType = strtoupper(trim($article->unit_type ?? ''));
        
        // Get appropriate calculator class, default to DefaultQuantityCalculator
        $calculatorClass = $this->calculators[$unitType] ?? DefaultQuantityCalculator::class;
        
        /** @var QuantityCalculatorInterface $calculator */
        $calculator = new $calculatorClass();
        
        return $calculator->calculate($article);
    }

    /**
     * Register a new calculator for a unit type
     * Useful for adding custom calculators at runtime
     * 
     * @param string $unitType
     * @param string $calculatorClass Must implement QuantityCalculatorInterface
     * @return void
     */
    public function registerCalculator(string $unitType, string $calculatorClass): void
    {
        if (!is_subclass_of($calculatorClass, QuantityCalculatorInterface::class)) {
            throw new \InvalidArgumentException(
                "Calculator class must implement QuantityCalculatorInterface"
            );
        }
        
        $this->calculators[strtoupper($unitType)] = $calculatorClass;
    }

    /**
     * Get all registered unit types
     * 
     * @return array
     */
    public function getRegisteredUnitTypes(): array
    {
        return array_keys($this->calculators);
    }
}


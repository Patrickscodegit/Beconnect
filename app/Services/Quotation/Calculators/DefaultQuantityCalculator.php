<?php

namespace App\Services\Quotation\Calculators;

use App\Models\QuotationRequestArticle;

class DefaultQuantityCalculator implements QuantityCalculatorInterface
{
    /**
     * Return stored quantity (no special calculation)
     * Used for unit types that don't require dimension-based calculations
     * 
     * @param QuotationRequestArticle $article
     * @return float
     */
    public function calculate(QuotationRequestArticle $article): float
    {
        return (float) $article->quantity;
    }
}


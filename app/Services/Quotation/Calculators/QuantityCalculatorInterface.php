<?php

namespace App\Services\Quotation\Calculators;

use App\Models\QuotationRequestArticle;

interface QuantityCalculatorInterface
{
    /**
     * Calculate the effective quantity for an article based on commodity items
     * 
     * @param QuotationRequestArticle $article
     * @return float
     */
    public function calculate(QuotationRequestArticle $article): float;
}


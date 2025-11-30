<?php

namespace App\Services\Quotation\Calculators;

use App\Models\QuotationRequestArticle;

class LmQuantityCalculator implements QuantityCalculatorInterface
{
    /**
     * Calculate LM quantity: Sum of [quantity × (length × width) / 2.5] for all commodity items
     * 
     * Formula per commodity item:
     * - LM per item = (length_cm × width_cm) / 2.5
     * - LM for item line = LM per item × item quantity
     * - Total LM = Sum of all item lines
     * 
     * Example:
     * - 3 trucks, each 500cm × 200cm
     * - LM per truck = (500 × 200) / 2.5 = 40,000
     * - Total LM = 40,000 × 3 = 120,000
     * 
     * @param QuotationRequestArticle $article
     * @return float
     */
    public function calculate(QuotationRequestArticle $article): float
    {
        $quotationRequest = $article->quotationRequest;
        
        if (!$quotationRequest) {
            return (float) $article->quantity; // Fallback to stored quantity
        }
        
        // Load commodity items if not already loaded
        if (!$quotationRequest->relationLoaded('commodityItems')) {
            $quotationRequest->load('commodityItems');
        }
        
        $commodityItems = $quotationRequest->commodityItems;
        
        if ($commodityItems->isEmpty()) {
            return (float) $article->quantity; // Fallback if no items
        }
        
        $totalLm = 0;
        
        foreach ($commodityItems as $item) {
            if ($item->length_cm && $item->width_cm) {
                // Calculate LM per item: (length × width) / 2.5
                $lmPerItem = ($item->length_cm * $item->width_cm) / 2.5;
                
                // Multiply by item quantity (e.g., 3 trucks with same dimensions)
                $itemQuantity = $item->quantity ?? 1;
                $lmForItemLine = $lmPerItem * $itemQuantity;
                
                $totalLm += $lmForItemLine;
            }
        }
        
        return $totalLm > 0 ? $totalLm : (float) $article->quantity;
    }
}


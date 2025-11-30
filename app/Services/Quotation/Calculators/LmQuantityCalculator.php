<?php

namespace App\Services\Quotation\Calculators;

use App\Models\QuotationRequestArticle;

class LmQuantityCalculator implements QuantityCalculatorInterface
{
    /**
     * Calculate LM quantity: Sum of [quantity × (length × width) / 2.5] for all commodity items
     * 
     * Formula per commodity item (converting cm to meters):
     * - LM per item = (length_cm / 100 × width_cm / 100) / 2.5 = (length_m × width_m) / 2.5
     * - Or equivalently: (length_cm × width_cm) / 25,000
     * - LM for item line = LM per item × item quantity
     * - Total LM = Sum of all item lines
     * 
     * Example:
     * - 3 trucks, each 500cm × 200cm
     * - LM per truck = (500 / 100 × 200 / 100) / 2.5 = (5 × 2) / 2.5 = 4 LM
     * - Total LM = 4 × 3 = 12 LM
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
                // Convert cm to meters and calculate LM per item: (length_m × width_m) / 2.5
                // Equivalent to: (length_cm × width_cm) / 25,000
                $lengthM = $item->length_cm / 100;
                $widthM = $item->width_cm / 100;
                $lmPerItem = ($lengthM * $widthM) / 2.5;
                
                // Multiply by item quantity (e.g., 3 trucks with same dimensions)
                $itemQuantity = $item->quantity ?? 1;
                $lmForItemLine = $lmPerItem * $itemQuantity;
                
                $totalLm += $lmForItemLine;
            }
        }
        
        return $totalLm > 0 ? $totalLm : (float) $article->quantity;
    }
}


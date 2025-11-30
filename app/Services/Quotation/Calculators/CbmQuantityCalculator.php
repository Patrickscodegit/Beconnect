<?php

namespace App\Services\Quotation\Calculators;

use App\Models\QuotationRequestArticle;

class CbmQuantityCalculator implements QuantityCalculatorInterface
{
    /**
     * Calculate CBM quantity: Sum of CBM for all commodity items
     * 
     * Formula per commodity item:
     * - CBM for item line = item CBM × item quantity
     * - Total CBM = Sum of all item lines
     * 
     * @param QuotationRequestArticle $article
     * @return float
     */
    public function calculate(QuotationRequestArticle $article): float
    {
        $quotationRequest = $article->quotationRequest;
        
        if (!$quotationRequest) {
            return (float) $article->quantity;
        }
        
        // Load commodity items if not already loaded
        if (!$quotationRequest->relationLoaded('commodityItems')) {
            $quotationRequest->load('commodityItems');
        }
        
        $commodityItems = $quotationRequest->commodityItems;
        
        if ($commodityItems->isEmpty()) {
            return (float) $article->quantity;
        }
        
        $totalCbm = 0;
        
        foreach ($commodityItems as $item) {
            if ($item->cbm) {
                // Multiply CBM by item quantity
                $itemQuantity = $item->quantity ?? 1;
                $totalCbm += $item->cbm * $itemQuantity;
            }
        }
        
        return $totalCbm > 0 ? $totalCbm : (float) $article->quantity;
    }
}


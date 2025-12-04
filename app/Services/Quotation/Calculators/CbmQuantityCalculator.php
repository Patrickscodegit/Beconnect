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
        $processedStacks = [];
        
        foreach ($commodityItems as $item) {
            // Skip if this item is part of a stack we've already processed
            if ($item->isInStack() && !$item->isStackBase()) {
                continue; // Only process stack bases
            }
            
            // Check if item is in a stack with stack dimensions
            if ($item->isStackBase() && $item->stack_cbm) {
                // Use stack CBM
                $baseId = $item->getStackGroup();
                if (in_array($baseId, $processedStacks)) {
                    continue; // Already processed this stack
                }
                $processedStacks[] = $baseId;
                
                // Multiply stack CBM by stack unit count (number of units in the stack)
                $stackUnitCount = $item->stack_unit_count ?? $item->getStackUnitCount() ?? 1;
                $totalCbm += $item->stack_cbm * $stackUnitCount;
            } elseif ($item->cbm) {
                // Use individual item CBM
                // Multiply CBM by item quantity
                $itemQuantity = $item->quantity ?? 1;
                $totalCbm += $item->cbm * $itemQuantity;
            }
        }
        
        return $totalCbm > 0 ? $totalCbm : (float) $article->quantity;
    }
}


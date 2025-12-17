<?php

namespace App\Services\Quotation\Calculators;

use App\Models\QuotationRequestArticle;

class LmQuantityCalculator implements QuantityCalculatorInterface
{
    /**
     * Calculate LM quantity: Sum of [quantity × (length × width) / 2.5] for all commodity items
     * 
     * Formula per commodity item (converting cm to meters):
     * - Width has a minimum of 250 cm (2.5m) for LM calculations
     * - LM per item = (length_cm / 100 × max(width_cm, 250) / 100) / 2.5 = (length_m × max(width_m, 2.5)) / 2.5
     * - Or equivalently: (length_cm × max(width_cm, 250)) / 25,000
     * - LM for item line = LM per item × item quantity
     * - Total LM = Sum of all item lines
     * 
     * Example:
     * - 3 trucks, each 500cm × 200cm (width treated as 250cm minimum)
     * - LM per truck = (500 / 100 × 250 / 100) / 2.5 = (5 × 2.5) / 2.5 = 5 LM
     * - Total LM = 5 × 3 = 15 LM
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
        $processedItems = [];
        
        foreach ($commodityItems as $item) {
            // Skip if already processed
            if (in_array($item->id, $processedItems)) {
                continue;
            }
            
            // Skip if this item is part of a stack and is NOT the base (it points to another item)
            if ($item->isInStack() && !$item->isStackBase()) {
                continue; // This item's dimensions are included in the stack base's calculation
            }
            
            // PRIORITY 1: Use stack/overall dimensions if available
            // This handles both actual stack bases AND items with overall dimensions set
            if ($item->stack_length_cm && $item->stack_width_cm) {
                $processedItems[] = $item->id;
                
                $lengthM = $item->stack_length_cm / 100;
                $widthCm = max($item->stack_width_cm, 250); // Minimum width of 250 cm
                $widthM = $widthCm / 100;
                $lmPerStack = ($lengthM * $widthM) / 2.5;
                
                // Multiply by stack unit count (number of units in the stack)
                $stackUnitCount = $item->stack_unit_count ?? $item->getStackUnitCount() ?? 1;
                $lmForStack = $lmPerStack * $stackUnitCount;
                
                $totalLm += $lmForStack;
            }
            // PRIORITY 2: Use individual item dimensions
            elseif ($item->length_cm && $item->width_cm) {
                $processedItems[] = $item->id;
                
                $lengthM = $item->length_cm / 100;
                $widthCm = max($item->width_cm, 250); // Minimum width of 250 cm
                $widthM = $widthCm / 100;
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


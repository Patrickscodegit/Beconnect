<?php

namespace App\Services\Quotation\Calculators;

use App\Models\QuotationRequestArticle;
use App\Services\CarrierRules\ChargeableMeasureService;

class LmQuantityCalculator implements QuantityCalculatorInterface
{
    /**
     * Calculate LM quantity: Sum of [quantity × chargeable LM] for all commodity items
     * Uses ChargeableMeasureService for carrier-aware LM calculations.
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
        
        // Load schedule with carrier for carrier-aware calculations
        if (!$quotationRequest->relationLoaded('selectedSchedule')) {
            $quotationRequest->load('selectedSchedule.carrier');
        }
        
        $commodityItems = $quotationRequest->commodityItems;
        
        if ($commodityItems->isEmpty()) {
            return (float) $article->quantity; // Fallback if no items
        }
        
        $service = app(ChargeableMeasureService::class);
        $schedule = $quotationRequest->selectedSchedule;
        
        // Get carrier context from schedule
        $carrierId = $schedule?->carrier_id;
        $portId = $schedule?->pod_id; // Fixed: use pod_id instead of pod_port_id
        $vesselName = $schedule?->vessel_name;
        $vesselClass = $schedule?->vessel_class;
        
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
            if ($item->stack_length_cm && $item->stack_width_cm) {
                $processedItems[] = $item->id;
                
                $result = $service->computeChargeableLm(
                    $item->stack_length_cm,
                    $item->stack_width_cm,
                    $carrierId,
                    $portId,
                    $item->category,
                    $vesselName,
                    $vesselClass
                );
                
                $lmPerStack = $result->chargeableLm;
                
                // Multiply by stack unit count (number of units in the stack)
                $stackUnitCount = $item->stack_unit_count ?? $item->getStackUnitCount() ?? 1;
                $lmForStack = $lmPerStack * $stackUnitCount;
                
                $totalLm += $lmForStack;
            }
            // PRIORITY 2: Use individual item dimensions
            elseif ($item->length_cm && $item->width_cm) {
                $processedItems[] = $item->id;
                
                $result = $service->computeChargeableLm(
                    $item->length_cm,
                    $item->width_cm,
                    $carrierId,
                    $portId,
                    $item->category,
                    $vesselName,
                    $vesselClass
                );
                
                $lmPerItem = $result->chargeableLm;
                
                // Multiply by item quantity (e.g., 3 trucks with same dimensions)
                $itemQuantity = $item->quantity ?? 1;
                $lmForItemLine = $lmPerItem * $itemQuantity;
                
                $totalLm += $lmForItemLine;
            }
        }
        
        return $totalLm > 0 ? $totalLm : (float) $article->quantity;
    }
}


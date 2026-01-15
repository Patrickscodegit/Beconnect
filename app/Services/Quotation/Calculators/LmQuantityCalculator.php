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

        $lmArticles = QuotationRequestArticle::query()
            ->where('quotation_request_id', $quotationRequest->id)
            ->get()
            ->filter(function ($candidate) use ($article) {
                $unitType = strtoupper(trim($candidate->unit_type ?? ''));
                return $unitType === 'LM'
                    && !$candidate->carrier_rule_applied
                    && $candidate->article_cache_id === $article->article_cache_id;
            })
            ->sortBy('id')
            ->values();

        $usePerStackAllocation = $lmArticles->count() > 1;
        $lmArticleIndex = $usePerStackAllocation
            ? $lmArticles->search(fn ($candidate) => $candidate->id === $article->id)
            : null;
        
        foreach ($commodityItems as $item) {
            // Skip if already processed
            if (in_array($item->id, $processedItems)) {
                continue;
            }
            
            // Skip if this item is part of a stack and is NOT the base (it points to another item)
            // The stack base will handle the calculation for the entire stack
            if ($item->isInStack() && !$item->isStackBase()) {
                continue; // This item's dimensions are included in the stack base's calculation
            }
            
            // PRIORITY 1: Use stack/overall dimensions if available (for stacks)
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
            // PRIORITY 2: Use individual item dimensions (for standalone items)
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

        if ($usePerStackAllocation && $lmArticleIndex !== false) {
            $stackGroups = \App\Models\QuotationCommodityItem::getAllStacks($quotationRequest->id);
            $stackEntries = collect($stackGroups)->map(function ($stack) use ($service, $carrierId, $portId, $vesselName, $vesselClass) {
                $baseItem = $stack->first(function ($candidate) {
                    return $candidate->id === $candidate->getStackGroup();
                }) ?? $stack->first();

                if (!$baseItem) {
                    return [
                        'line_number' => null,
                        'lm' => 0,
                    ];
                }

                $lm = 0.0;
                if ($baseItem->stack_length_cm && $baseItem->stack_width_cm) {
                    $result = $service->computeChargeableLm(
                        $baseItem->stack_length_cm,
                        $baseItem->stack_width_cm,
                        $carrierId,
                        $portId,
                        $baseItem->category,
                        $vesselName,
                        $vesselClass
                    );
                    $stackUnitCount = $baseItem->stack_unit_count ?? $baseItem->getStackUnitCount() ?? 1;
                    $lm = $result->chargeableLm * $stackUnitCount;
                } elseif ($baseItem->length_cm && $baseItem->width_cm) {
                    $result = $service->computeChargeableLm(
                        $baseItem->length_cm,
                        $baseItem->width_cm,
                        $carrierId,
                        $portId,
                        $baseItem->category,
                        $vesselName,
                        $vesselClass
                    );
                    $itemQuantity = $baseItem->quantity ?? 1;
                    $lm = $result->chargeableLm * $itemQuantity;
                }

                return [
                    'line_number' => $baseItem->line_number ?? null,
                    'lm' => $lm,
                ];
            })
            ->sortBy('line_number')
            ->values();

            if ($stackEntries->has($lmArticleIndex)) {
                return $stackEntries[$lmArticleIndex]['lm'] ?? 0.0;
            }
        }
        
        return $totalLm > 0 ? $totalLm : (float) $article->quantity;
    }
}


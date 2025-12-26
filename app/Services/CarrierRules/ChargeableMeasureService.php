<?php

namespace App\Services\CarrierRules;

use App\Models\CarrierTransformRule;

/**
 * Single source of truth for LM/chargeable measure calculation.
 * Replaces hardcoded logic in 3 places:
 * - QuotationCommodityItem::calculateLm()
 * - LmQuantityCalculator::calculate()
 * - CommodityItemsRepeater::calculateLm()
 */
class ChargeableMeasureService
{
    /**
     * Compute chargeable LM with carrier-aware transforms
     * 
     * @param float $lengthCm
     * @param float $widthCm
     * @param int $carrierId
     * @param ?int $portId
     * @param ?string $vehicleCategory
     * @param ?string $vesselName
     * @param ?string $vesselClass
     * @return ChargeableMeasureDTO
     */
    public function computeChargeableLm(
        float $lengthCm,
        float $widthCm,
        int $carrierId,
        ?int $portId = null,
        ?string $vehicleCategory = null,
        ?string $vesselName = null,
        ?string $vesselClass = null
    ): ChargeableMeasureDTO {
        // 1. Compute base ISO LM: (L_m × max(W_m, 2.5)) / 2.5
        $baseLm = ($lengthCm / 100 * max($widthCm / 100, 2.5)) / 2.5;
        
        // 2. Check for transform rules (OVERWIDTH_LM_RECALC)
        // For now, query directly - will use CarrierRuleResolver when available
        $transformRules = CarrierTransformRule::where('carrier_id', $carrierId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('effective_from')
                  ->orWhere('effective_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', now());
            })
            ->when($portId, function ($q) use ($portId) {
                $q->where(function ($query) use ($portId) {
                    $query->whereNull('port_id')
                          ->orWhere('port_id', $portId);
                });
            })
            ->when($vehicleCategory, function ($q) use ($vehicleCategory) {
                $q->where(function ($query) use ($vehicleCategory) {
                    $query->whereNull('vehicle_category')
                          ->orWhere('vehicle_category', $vehicleCategory);
                });
            })
            ->when($vesselName, function ($q) use ($vesselName) {
                $q->where(function ($query) use ($vesselName) {
                    $query->whereNull('vessel_name')
                          ->orWhere('vessel_name', $vesselName);
                });
            })
            ->when($vesselClass, function ($q) use ($vesselClass) {
                $q->where(function ($query) use ($vesselClass) {
                    $query->whereNull('vessel_class')
                          ->orWhere('vessel_class', $vesselClass);
                });
            })
            ->orderBy('priority', 'desc')
            ->orderBy('effective_from', 'desc')
            ->orderBy('id', 'desc')
            ->get();
        
        $chargeableLm = $baseLm;
        $appliedTransformRuleId = null;
        $meta = [];
        
        foreach ($transformRules as $rule) {
            if ($rule->transform_code === 'OVERWIDTH_LM_RECALC' && $rule->triggers($widthCm)) {
                $divisorCm = $rule->getDivisorCm();
                
                // Pro-rata LM: (L_cm × W_cm) / (divisor_cm × 100)
                // Careful: (L_cm/100 * W_cm/100) / (divisor_cm/100)
                // Equivalent: (L_cm * W_cm) / (divisor_cm * 100)
                $chargeableLm = ($lengthCm * $widthCm) / ($divisorCm * 100);
                $appliedTransformRuleId = $rule->id;
                $meta['transform_reason'] = "Overwidth: width {$widthCm}cm exceeds trigger {$rule->params['trigger_width_gt_cm']}cm";
                $meta['divisor_cm'] = $divisorCm;
                break; // Apply first matching rule
            }
        }
        
        return new ChargeableMeasureDTO(
            baseLm: $baseLm,
            chargeableLm: $chargeableLm,
            appliedTransformRuleId: $appliedTransformRuleId,
            meta: $meta
        );
    }
}

/**
 * DTO for chargeable measure calculation results
 */
class ChargeableMeasureDTO
{
    public function __construct(
        public float $baseLm,
        public float $chargeableLm,
        public ?int $appliedTransformRuleId,
        public array $meta
    ) {}
}


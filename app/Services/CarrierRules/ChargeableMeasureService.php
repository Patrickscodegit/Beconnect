<?php

namespace App\Services\CarrierRules;

use App\Services\CarrierRules\CarrierRuleResolver;

/**
 * Single source of truth for LM/chargeable measure calculation.
 * Replaces hardcoded logic in 3 places:
 * - QuotationCommodityItem::calculateLm()
 * - LmQuantityCalculator::calculate()
 * - CommodityItemsRepeater::calculateLm()
 */
class ChargeableMeasureService
{
    public function __construct(
        private CarrierRuleResolver $resolver
    ) {}

    /**
     * Calculate base ISO LM without carrier transforms (fallback)
     * Formula: (length_m × max(width_m, 2.5)) / 2.5
     */
    public function calculateBaseLm(float $lengthCm, float $widthCm): float
    {
        if ($lengthCm <= 0 || $widthCm <= 0) {
            return 0;
        }
        $lengthM = $lengthCm / 100;
        $widthM = max($widthCm / 100, 2.5); // Minimum width of 2.5m
        return ($lengthM * $widthM) / 2.5;
    }

    /**
     * Compute chargeable LM with carrier-aware transforms
     * 
     * @param float $lengthCm
     * @param float $widthCm
     * @param ?int $carrierId If null, returns base ISO LM only
     * @param ?int $portId
     * @param ?string $vehicleCategory
     * @param ?string $vesselName
     * @param ?string $vesselClass
     * @return ChargeableMeasureDTO
     */
    public function computeChargeableLm(
        float $lengthCm,
        float $widthCm,
        ?int $carrierId = null,
        ?int $portId = null,
        ?string $vehicleCategory = null,
        ?string $vesselName = null,
        ?string $vesselClass = null
    ): ChargeableMeasureDTO {
        // 1. Compute base ISO LM: (L_m × max(W_m, 2.5)) / 2.5
        $baseLm = $this->calculateBaseLm($lengthCm, $widthCm);
        
        // 2. If no carrier context, return base LM only
        if (!$carrierId) {
            return new ChargeableMeasureDTO(
                baseLm: $baseLm,
                chargeableLm: $baseLm,
                appliedTransformRuleId: null,
                meta: []
            );
        }
        
        // 3. Check for transform rules (OVERWIDTH_LM_RECALC) using resolver
        $transformRules = $this->resolver->resolveTransformRules(
            $carrierId,
            $portId,
            $vehicleCategory,
            null, // category_group_id - not used for transforms in this context
            $vesselName,
            $vesselClass
        );
        
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


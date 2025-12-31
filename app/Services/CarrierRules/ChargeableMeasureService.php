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
        
        // 3. Derive category group ID from vehicle category if available
        $categoryGroupId = null;
        if ($vehicleCategory && $carrierId) {
            $member = \App\Models\CarrierCategoryGroupMember::whereHas('categoryGroup', function ($q) use ($carrierId) {
                $q->where('carrier_id', $carrierId)->where('is_active', true);
            })
            ->where('vehicle_category', $vehicleCategory)
            ->where('is_active', true)
            ->first();
            $categoryGroupId = $member?->carrier_category_group_id;
        }
        
        // 4. Check for transform rules (OVERWIDTH_LM_RECALC) using resolver
        $transformRules = $this->resolver->resolveTransformRules(
            $carrierId,
            $portId,
            $vehicleCategory,
            $categoryGroupId, // Now derived from vehicle category
            $vesselName,
            $vesselClass
        );
        
        $chargeableLm = $baseLm;
        $appliedTransformRuleId = null;
        $meta = [];
        
        // Check if there are any matching transform rules for this carrier/port
        $hasMatchingRules = $transformRules->isNotEmpty();
        
        if ($hasMatchingRules) {
            // Carrier has rules for this port: apply carrier-specific logic
            // Select the most specific rule (port-specific > port-group > global)
            // Use selectMostSpecific to ensure port-specific rules take precedence
            $portGroupIds = [];
            if ($portId !== null) {
                $portGroupIds = $this->resolver->resolvePortGroupIdsForPort($carrierId, $portId);
            }
            $mostSpecificRule = $this->resolver->selectMostSpecific(
                $transformRules,
                $portId,
                $vesselName,
                $vesselClass,
                $vehicleCategory,
                $categoryGroupId, // Pass the derived category group ID
                $portGroupIds
            );
            
            // Extract trigger_width_gt_cm from the most specific rule
            if (!$mostSpecificRule) {
                // Fallback if no rule selected (shouldn't happen, but safety check)
                $mostSpecificRule = $transformRules->first();
            }
            $triggerWidthCm = $mostSpecificRule->params['trigger_width_gt_cm'] ?? 260.0;
            $meta['trigger_width_cm'] = $triggerWidthCm;
            
            // Minimum width is always 250 cm (2.5m) when rules exist
            $minWidthCm = 250.0;
            
            // If width <= trigger_width_gt_cm, use minimum width of 250 cm and divide by 250 cm
            // This ensures consistent calculation for widths at or below the overwidth threshold
            // Transform rules trigger only when width > trigger_width_gt_cm
            if ($widthCm <= $triggerWidthCm) {
                // Always use 250 cm as the effective width when below trigger (minimum width rule)
                $effectiveWidthCm = $minWidthCm;
                $divisorCm = 250.0;
                $chargeableLm = ($lengthCm * $effectiveWidthCm) / ($divisorCm * 100);
                $meta['transform_reason'] = "Width {$widthCm}cm <= {$triggerWidthCm}cm: using minimum width {$effectiveWidthCm}cm, divided by {$divisorCm}cm (carrier rule)";
                $meta['effective_width_cm'] = $effectiveWidthCm;
                $meta['divisor_cm'] = $divisorCm;
            } else {
                // Width > trigger_width_gt_cm: apply the most specific transform rule
                if ($mostSpecificRule && $mostSpecificRule->transform_code === 'OVERWIDTH_LM_RECALC' && $mostSpecificRule->triggers($widthCm)) {
                    $divisorCm = $mostSpecificRule->getDivisorCm();
                    
                    // Pro-rata LM: (L_cm × W_cm) / (divisor_cm × 100)
                    // Careful: (L_cm/100 * W_cm/100) / (divisor_cm/100)
                    // Equivalent: (L_cm * W_cm) / (divisor_cm * 100)
                    $chargeableLm = ($lengthCm * $widthCm) / ($divisorCm * 100);
                    $appliedTransformRuleId = $mostSpecificRule->id;
                    $meta['transform_reason'] = "Overwidth: width {$widthCm}cm exceeds trigger {$mostSpecificRule->params['trigger_width_gt_cm']}cm";
                    $meta['divisor_cm'] = $divisorCm;
                }
            }
        } else {
            // No carrier rules for this port: use global fallback formula L×max(W,250)/250
            // Formula: (L_cm × max(W_cm, 250)) / (250 × 100)
            $effectiveWidthCm = max($widthCm, 250.0);
            $divisorCm = 250.0;
            $chargeableLm = ($lengthCm * $effectiveWidthCm) / ($divisorCm * 100);
            $meta['transform_reason'] = "No carrier rules for this port: using global fallback formula L×max(W,250)/250";
            $meta['effective_width_cm'] = $effectiveWidthCm;
            $meta['divisor_cm'] = $divisorCm;
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


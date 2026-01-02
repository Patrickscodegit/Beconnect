<?php

namespace App\Services\Pricing;

use App\Models\PricingProfile;
use App\Models\PricingRule;

class MarginCalculator
{
    /**
     * Calculate margin based on vehicle category, unit basis, purchase amount, and profile.
     * 
     * Matching priority (fallback order):
     * 1. Exact match (vehicle_category + unit_basis)
     * 2. Category-only (vehicle_category, any unit_basis)
     * 3. Basis-only (unit_basis, any vehicle_category)
     * 4. Global (both null)
     *
     * @param string|null $vehicleCategory
     * @param string $unitBasis
     * @param float $purchaseAmount
     * @param PricingProfile $profile
     * @return float
     */
    public function calculateMargin(
        ?string $vehicleCategory,
        string $unitBasis,
        float $purchaseAmount,
        PricingProfile $profile
    ): float {
        $rules = $profile->rules()->active()->get();

        // Priority 1: Exact match (vehicle_category + unit_basis)
        if ($vehicleCategory) {
            $rule = $rules->first(function ($rule) use ($vehicleCategory, $unitBasis) {
                return $rule->vehicle_category === $vehicleCategory 
                    && $rule->unit_basis === $unitBasis;
            });
            
            if ($rule) {
                return $this->applyMargin($rule, $purchaseAmount);
            }
        }

        // Priority 2: Category-only (vehicle_category matches, unit_basis is null in rule)
        if ($vehicleCategory) {
            $rule = $rules->first(function ($rule) use ($vehicleCategory) {
                return $rule->vehicle_category === $vehicleCategory 
                    && $rule->unit_basis === null;
            });
            
            if ($rule) {
                return $this->applyMargin($rule, $purchaseAmount);
            }
        }

        // Priority 3: Basis-only (unit_basis matches, vehicle_category is null in rule)
        $rule = $rules->first(function ($rule) use ($unitBasis) {
            return $rule->vehicle_category === null 
                && $rule->unit_basis === $unitBasis;
        });
        
        if ($rule) {
            return $this->applyMargin($rule, $purchaseAmount);
        }

        // Priority 4: Global (both null in rule)
        $rule = $rules->first(function ($rule) {
            return $rule->vehicle_category === null 
                && ($rule->unit_basis === null || $rule->unit_basis === '');
        });
        
        if ($rule) {
            return $this->applyMargin($rule, $purchaseAmount);
        }

        // No match found
        return 0.0;
    }

    /**
     * Apply margin calculation based on margin type.
     *
     * @param PricingRule $rule
     * @param float $purchaseAmount
     * @return float
     */
    protected function applyMargin(PricingRule $rule, float $purchaseAmount): float
    {
        if ($rule->margin_type === 'FIXED') {
            return (float) $rule->margin_value;
        }

        if ($rule->margin_type === 'PERCENT') {
            return $purchaseAmount * ((float) $rule->margin_value / 100);
        }

        return 0.0;
    }
}

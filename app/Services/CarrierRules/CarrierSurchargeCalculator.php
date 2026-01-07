<?php

namespace App\Services\CarrierRules;

use App\Models\CarrierSurchargeRule;
use App\Services\CarrierRules\DTOs\CargoInputDTO;
use InvalidArgumentException;

/**
 * Handles calculation of surcharge amounts based on calc_mode enum (no arbitrary eval).
 * Supports WIDTH_LM_BASIS and WIDTH_STEP_BLOCKS calc_modes.
 */
class CarrierSurchargeCalculator
{
    /**
     * Calculate surcharge event with quantity and amount basis
     * 
     * @param CarrierSurchargeRule $rule
     * @param CargoInputDTO $input
     * @param ChargeableMeasureDTO $chargeableMeasure
     * @param ?float $basicFreight
     * @return array ['qty' => float, 'amount_basis' => string, 'needs_basic_freight' => bool]
     */
    public function calculate(
        CarrierSurchargeRule $rule,
        CargoInputDTO $input,
        ChargeableMeasureDTO $chargeableMeasure,
        ?float $basicFreight = null
    ): array {
        $calcMode = $rule->calc_mode;
        $params = $rule->params;

        return match ($calcMode) {
            'FLAT' => $this->calculateFlat($params),
            'PER_UNIT' => $this->calculatePerUnit($params, $input->unitCount),
            'PERCENT_OF_BASIC_FREIGHT' => $this->calculatePercentOfBasicFreight($params, $basicFreight),
            'WEIGHT_TIER' => $this->calculateWeightTier($params, $input->weightKg),
            'PER_TON_ABOVE' => $this->calculatePerTonAbove($params, $input->weightKg),
            'PER_TANK' => $this->calculatePerTank($params, $input->unitCount),
            'PER_LM' => $this->calculatePerLm($params, $chargeableMeasure->chargeableLm),
            'WIDTH_LM_BASIS' => $this->calculateWidthLmBasis($params, $input->widthCm, $chargeableMeasure),
            'WIDTH_STEP_BLOCKS' => $this->calculateWidthStepBlocks($params, $input->widthCm, $chargeableMeasure, $input->unitCount),
            default => throw new InvalidArgumentException("Unknown calc_mode: {$calcMode}"),
        };
    }

    /**
     * FLAT: Fixed amount
     */
    private function calculateFlat(array $params): array
    {
        return [
            'qty' => 1,
            'amount_basis' => 'FLAT',
            'amount' => $params['amount'] ?? 0,
            'needs_basic_freight' => false,
        ];
    }

    /**
     * PER_UNIT: Amount per unit
     */
    private function calculatePerUnit(array $params, int $unitCount): array
    {
        $amountPerUnit = $params['amount'] ?? 0;
        return [
            'qty' => $unitCount,
            'amount_basis' => 'PER_UNIT',
            'amount' => $amountPerUnit,
            'needs_basic_freight' => false,
        ];
    }

    /**
     * PERCENT_OF_BASIC_FREIGHT: Percentage of basic freight amount
     */
    private function calculatePercentOfBasicFreight(array $params, ?float $basicFreight): array
    {
        if ($basicFreight === null || $basicFreight <= 0) {
            return [
                'qty' => 0,
                'amount_basis' => 'PERCENT_OF_BASIC_FREIGHT',
                'amount' => 0,
                'needs_basic_freight' => true,
            ];
        }

        $percentage = $params['percentage'] ?? 0;
        return [
            'qty' => 1,
            'amount_basis' => 'PERCENT_OF_BASIC_FREIGHT',
            'amount' => ($basicFreight * $percentage) / 100,
            'needs_basic_freight' => false,
        ];
    }

    /**
     * WEIGHT_TIER: Amount based on weight tiers
     */
    private function calculateWeightTier(array $params, float $weightKg): array
    {
        $tiers = $params['tiers'] ?? [];
        $matchedTier = null;
        $lastTierWithNullMax = null; // Track catch-all tier

        foreach ($tiers as $tier) {
            $maxKg = $tier['max_kg'] ?? null;
            $minKg = $tier['min_kg'] ?? null;

            if ($maxKg !== null && $weightKg <= $maxKg) {
                $matchedTier = $tier;
                break;
            } elseif ($minKg !== null && $weightKg >= $minKg) {
                $matchedTier = $tier;
                // Continue to check if there's a more specific tier
            }
            
            // Track the last tier with null max_kg (catch-all)
            if ($maxKg === null) {
                $lastTierWithNullMax = $tier;
            }
        }

        // If no tier matched but we have a catch-all tier, use it
        if (!$matchedTier && $lastTierWithNullMax !== null) {
            $matchedTier = $lastTierWithNullMax;
        }

        if (!$matchedTier) {
            return [
                'qty' => 0,
                'amount_basis' => 'WEIGHT_TIER',
                'amount' => 0,
                'needs_basic_freight' => false,
            ];
        }

        $amount = $matchedTier['amount'] ?? 0;
        $perTonOver = $matchedTier['per_ton_over'] ?? null;

        if ($perTonOver !== null && isset($matchedTier['min_kg'])) {
            $tonsOver = ($weightKg - $matchedTier['min_kg']) / 1000;
            $amount += $tonsOver * $perTonOver;
        }

        return [
            'qty' => 1,
            'amount_basis' => 'WEIGHT_TIER',
            'amount' => $amount,
            'needs_basic_freight' => false,
        ];
    }

    /**
     * PER_TON_ABOVE: Amount per ton above threshold
     */
    private function calculatePerTonAbove(array $params, float $weightKg): array
    {
        $thresholdKg = $params['threshold_kg'] ?? 0;
        $amountPerTon = $params['amount_per_ton'] ?? 0;

        if ($weightKg <= $thresholdKg) {
            return [
                'qty' => 0,
                'amount_basis' => 'PER_TON_ABOVE',
                'amount' => 0,
                'needs_basic_freight' => false,
            ];
        }

        $tonsOver = ($weightKg - $thresholdKg) / 1000;
        return [
            'qty' => $tonsOver,
            'amount_basis' => 'PER_TON_ABOVE',
            'amount' => $amountPerTon,
            'needs_basic_freight' => false,
        ];
    }

    /**
     * PER_TANK: Amount per tank unit
     */
    private function calculatePerTank(array $params, int $unitCount): array
    {
        $amountPerTank = $params['amount'] ?? 0;
        return [
            'qty' => $unitCount,
            'amount_basis' => 'PER_TANK',
            'amount' => $amountPerTank,
            'needs_basic_freight' => false,
        ];
    }

    /**
     * PER_LM: Amount per LM
     */
    private function calculatePerLm(array $params, float $lm): array
    {
        $amountPerLm = $params['amount'] ?? 0;
        return [
            'qty' => $lm,
            'amount_basis' => 'PER_LM',
            'amount' => $amountPerLm,
            'needs_basic_freight' => false,
        ];
    }

    /**
     * WIDTH_LM_BASIS: Quantity = LM value directly (L×W/2.5)
     */
    private function calculateWidthLmBasis(
        array $params,
        float $widthCm,
        ChargeableMeasureDTO $chargeableMeasure
    ): array {
        $triggerWidth = $params['trigger_width_gt_cm'] ?? 250;
        $useChargeableLm = $params['use_chargeable_lm'] ?? true;
        $amountPerLm = $params['amount_per_lm'] ?? 0;

        if ($widthCm <= $triggerWidth) {
            return [
                'qty' => 0,
                'amount_basis' => 'WIDTH_LM_BASIS',
                'amount' => 0,
                'needs_basic_freight' => false,
            ];
        }

        // Quantity = LM value directly
        $qty = $useChargeableLm ? $chargeableMeasure->chargeableLm : $chargeableMeasure->baseLm;

        return [
            'qty' => $qty,
            'amount_basis' => 'WIDTH_LM_BASIS',
            'amount' => $amountPerLm, // Amount per LM from params
            'needs_basic_freight' => false,
        ];
    }

    /**
     * WIDTH_STEP_BLOCKS: Quantity = blocks × LM (step-based calculation)
     */
    private function calculateWidthStepBlocks(
        array $params,
        float $widthCm,
        ChargeableMeasureDTO $chargeableMeasure,
        int $unitCount
    ): array {
        $threshold = $params['threshold_cm'] ?? 250;
        $blockCm = $params['block_cm'] ?? 25;
        $triggerWidth = $params['trigger_width_gt_cm'] ?? $threshold;
        $qtyBasis = $params['qty_basis'] ?? 'LM';
        $amountPerBlock = $params['amount_per_block'] ?? 0;

        if ($widthCm <= $triggerWidth) {
            return [
                'qty' => 0,
                'amount_basis' => 'WIDTH_STEP_BLOCKS',
                'amount' => 0,
                'needs_basic_freight' => false,
            ];
        }

        $overWidth = max(0, $widthCm - $threshold);
        $blocks = ceil($overWidth / $blockCm); // Number of blocks

        $qty = $blocks * ($qtyBasis === 'LM' ? $chargeableMeasure->baseLm : $unitCount);

        return [
            'qty' => $qty,
            'amount_basis' => 'WIDTH_STEP_BLOCKS',
            'amount' => $amountPerBlock, // Amount per block
            'needs_basic_freight' => false,
        ];
    }
}


<?php

namespace App\Services\CarrierRules;

use App\Models\CarrierAcceptanceRule;
use App\Models\CarrierCategoryGroup;
use App\Models\CarrierCategoryGroupMember;
use App\Models\CarrierSurchargeArticleMap;
use App\Services\CarrierRules\DTOs\CargoInputDTO;
use App\Services\CarrierRules\DTOs\CarrierRuleResultDTO;
use Illuminate\Support\Collection;

/**
 * Main engine that processes cargo through all rule types and produces DTO output.
 * 
 * Processing Order:
 * 1. Derive category group membership from vehicle category
 * 2. Resolve acceptance rule and validate limits/operational flags
 * 3. Compute LM via ChargeableMeasureService (base + transforms)
 * 4. Compute surcharge events (with exclusive_group logic)
 * 5. Map events to articles via carrier_surcharge_article_maps → quote_line_drafts
 */
class CarrierRuleEngine
{
    public function __construct(
        private CarrierRuleResolver $resolver,
        private ChargeableMeasureService $chargeableMeasureService,
        private CarrierSurchargeCalculator $surchargeCalculator
    ) {}

    /**
     * Process cargo through all rule types
     */
    public function processCargo(CargoInputDTO $input): CarrierRuleResultDTO
    {
        // 1. Get vehicle category from input (users always provide category)
        $vehicleCategory = $input->category;

        // 2. Derive categoryGroupId from vehicle category
        $categoryGroupId = $input->categoryGroupId;
        if ($categoryGroupId === null && $vehicleCategory !== null) {
            $categoryGroupId = $this->deriveCategoryGroup($input->carrierId, $vehicleCategory);
        }

        // 3. Resolve acceptance rule and validate
        $acceptanceResult = $this->validateAcceptance($input, $vehicleCategory, $categoryGroupId);

        // 4. Compute LM via ChargeableMeasureService
        $chargeableMeasure = $this->chargeableMeasureService->computeChargeableLm(
            $input->lengthCm,
            $input->widthCm,
            $input->carrierId,
            $input->podPortId,
            $vehicleCategory,
            $input->vesselName,
            $input->vesselClass
        );

        // 5. Compute surcharge events (with exclusive_group logic)
        $surchargeEvents = $this->calculateSurchargeEvents(
            $input,
            $vehicleCategory,
            $categoryGroupId,
            $chargeableMeasure,
            $acceptanceResult['basicFreight'] ?? null
        );

        // 6. Map events to articles
        $quoteLineDrafts = $this->mapEventsToArticles(
            $surchargeEvents,
            $input->carrierId,
            $input->podPortId,
            $vehicleCategory,
            $categoryGroupId,
            $input->vesselName,
            $input->vesselClass
        );

        return new CarrierRuleResultDTO(
            classifiedVehicleCategory: $vehicleCategory,
            matchedCategoryGroup: $this->getCategoryGroupCode($input->carrierId, $categoryGroupId),
            acceptanceStatus: $acceptanceResult['status'],
            violations: $acceptanceResult['violations'],
            approvalsRequired: $acceptanceResult['approvalsRequired'],
            warnings: $acceptanceResult['warnings'] ?? [],
            chargeableMeasure: $chargeableMeasure,
            surchargeEvents: $surchargeEvents,
            quoteLineDrafts: $quoteLineDrafts,
        );
    }

    /**
     * Derive category group membership
     */
    private function deriveCategoryGroup(int $carrierId, ?string $vehicleCategory): ?int
    {
        if (!$vehicleCategory) {
            return null;
        }

        $member = CarrierCategoryGroupMember::whereHas('categoryGroup', function ($q) use ($carrierId) {
            $q->where('carrier_id', $carrierId)->active();
        })
        ->where('vehicle_category', $vehicleCategory)
        ->where('is_active', true)
        ->first();

        return $member?->carrier_category_group_id;
    }

    /**
     * Get category group code
     */
    private function getCategoryGroupCode(int $carrierId, ?int $categoryGroupId): ?string
    {
        if (!$categoryGroupId) {
            return null;
        }

        $group = CarrierCategoryGroup::where('carrier_id', $carrierId)
            ->where('id', $categoryGroupId)
            ->first();

        return $group?->code;
    }

    /**
     * Validate acceptance
     */
    private function validateAcceptance(
        CargoInputDTO $input,
        ?string $vehicleCategory,
        ?int $categoryGroupId
    ): array {
        $rule = $this->resolver->resolveAcceptanceRule(
            $input->carrierId,
            $input->podPortId,
            $vehicleCategory,
            $categoryGroupId,
            $input->vesselName,
            $input->vesselClass
        );

        if (!$rule) {
            return [
                'status' => 'ALLOWED',
                'violations' => [],
                'approvalsRequired' => [],
                'warnings' => [],
            ];
        }

        $violations = [];
        $warnings = [];
        $approvalsRequired = [];
        $status = 'ALLOWED';

        // Check minimum limits
        if ($rule->min_length_cm !== null && $input->lengthCm < $rule->min_length_cm) {
            if ($rule->min_is_hard) {
                $violations[] = 'min_length_below';
                $status = 'NOT_ALLOWED';
            } else {
                $warnings[] = 'min_length_below';
            }
        }
        if ($rule->min_width_cm !== null && $input->widthCm < $rule->min_width_cm) {
            if ($rule->min_is_hard) {
                $violations[] = 'min_width_below';
                $status = 'NOT_ALLOWED';
            } else {
                $warnings[] = 'min_width_below';
            }
        }
        if ($rule->min_height_cm !== null && $input->heightCm < $rule->min_height_cm) {
            if ($rule->min_is_hard) {
                $violations[] = 'min_height_below';
                $status = 'NOT_ALLOWED';
            } else {
                $warnings[] = 'min_height_below';
            }
        }
        if ($rule->min_cbm !== null && $input->cbm < $rule->min_cbm) {
            if ($rule->min_is_hard) {
                $violations[] = 'min_cbm_below';
                $status = 'NOT_ALLOWED';
            } else {
                $warnings[] = 'min_cbm_below';
            }
        }
        if ($rule->min_weight_kg !== null && $input->weightKg < $rule->min_weight_kg) {
            if ($rule->min_is_hard) {
                $violations[] = 'min_weight_below';
                $status = 'NOT_ALLOWED';
            } else {
                $warnings[] = 'min_weight_below';
            }
        }

        // Check hard limits (max)
        if ($rule->max_length_cm && $input->lengthCm > $rule->max_length_cm) {
            $violations[] = 'max_length_exceeded';
            $status = 'NOT_ALLOWED';
        }
        if ($rule->max_width_cm && $input->widthCm > $rule->max_width_cm) {
            $violations[] = 'max_width_exceeded';
            $status = 'NOT_ALLOWED';
        }
        if ($rule->max_height_cm && $input->heightCm > $rule->max_height_cm) {
            // Check if within soft limit range (exceeds max but within soft_max)
            if ($rule->soft_max_height_cm && 
                $input->heightCm <= $rule->soft_max_height_cm && 
                $rule->soft_height_requires_approval) {
                // Within soft limit range - requires approval
                $approvalsRequired[] = 'soft_height_approval';
                $status = 'ALLOWED_UPON_REQUEST';
            } else {
                // Exceeds soft limit or no soft limit configured - not allowed
            $violations[] = 'max_height_exceeded';
            $status = 'NOT_ALLOWED';
            }
        }
        if ($rule->max_cbm && $input->cbm > $rule->max_cbm) {
            $violations[] = 'max_cbm_exceeded';
            $status = 'NOT_ALLOWED';
        }
        if ($rule->max_weight_kg && $input->weightKg > $rule->max_weight_kg) {
            // Check if within soft limit range (exceeds max but within soft_max)
            if ($rule->soft_max_weight_kg && 
                $input->weightKg <= $rule->soft_max_weight_kg && 
                $rule->soft_weight_requires_approval) {
                // Within soft limit range - requires approval
                $approvalsRequired[] = 'soft_weight_approval';
                $status = 'ALLOWED_UPON_REQUEST';
            } else {
                // Exceeds soft limit or no soft limit configured - not allowed
                $violations[] = 'max_weight_exceeded';
                $status = 'NOT_ALLOWED';
            }
        }

        // Check operational requirements
        if ($rule->must_be_empty && !in_array('empty', $input->flags)) {
            $violations[] = 'must_be_empty_required';
            $status = 'NOT_ALLOWED';
        }
        if (!$rule->must_be_self_propelled && in_array('non_self_propelled', $input->flags)) {
            $violations[] = 'must_be_self_propelled_required';
            $status = 'NOT_ALLOWED';
        }

        // If violations exist, status is already NOT_ALLOWED
        // If approvals required but no violations, status is ALLOWED_UPON_REQUEST
        // If surcharges will apply, status becomes ALLOWED_WITH_SURCHARGES (set later)

        return [
            'status' => $status,
            'violations' => $violations,
            'approvalsRequired' => $approvalsRequired,
            'warnings' => $warnings,
            'basicFreight' => null, // Will be calculated from articles
        ];
    }

    /**
     * Calculate surcharge events
     */
    private function calculateSurchargeEvents(
        CargoInputDTO $input,
        ?string $vehicleCategory,
        ?int $categoryGroupId,
        ChargeableMeasureDTO $chargeableMeasure,
        ?float $basicFreight
    ): array {
        $rules = $this->resolver->resolveSurchargeRules(
            $input->carrierId,
            $input->podPortId,
            $vehicleCategory,
            $categoryGroupId,
            $input->vesselName,
            $input->vesselClass
        );

        $events = [];
        $exclusiveGroups = []; // Track exclusive groups to prevent double charging

        foreach ($rules as $rule) {
            // Check exclusive group
            $exclusiveGroup = $rule->getExclusiveGroup();
            if ($exclusiveGroup && isset($exclusiveGroups[$exclusiveGroup])) {
                continue; // Skip if another rule in same exclusive group already applied
            }

            $calculation = $this->surchargeCalculator->calculate(
                $rule,
                $input,
                $chargeableMeasure,
                $basicFreight
            );

            // Skip if quantity is 0 or needs basic freight
            if ($calculation['qty'] <= 0 || $calculation['needs_basic_freight']) {
                continue;
            }

            $events[] = [
                'event_code' => $rule->event_code,
                'qty' => $calculation['qty'],
                'amount_basis' => $calculation['amount_basis'],
                'amount' => $calculation['amount'] ?? 0,
                'params' => $rule->params,
                'matched_rule_id' => $rule->id,
                'reason' => $this->getSurchargeReason($rule, $input, $calculation),
            ];

            // Mark exclusive group as used
            if ($exclusiveGroup) {
                $exclusiveGroups[$exclusiveGroup] = true;
            }
        }

        return $events;
    }

    /**
     * Get human-readable reason for surcharge
     */
    private function getSurchargeReason(
        \App\Models\CarrierSurchargeRule $rule,
        CargoInputDTO $input,
        array $calculation
    ): string {
        $reason = $rule->name;

        if ($rule->calc_mode === 'WIDTH_LM_BASIS') {
            $trigger = $rule->params['trigger_width_gt_cm'] ?? 250;
            $reason .= " (width {$input->widthCm}cm exceeds {$trigger}cm)";
        } elseif ($rule->calc_mode === 'WIDTH_STEP_BLOCKS') {
            $threshold = $rule->params['threshold_cm'] ?? 250;
            $blockCm = $rule->params['block_cm'] ?? 25;
            $blocks = ceil(($input->widthCm - $threshold) / $blockCm);
            $reason .= " ({$blocks} blocks × {$blockCm}cm over {$threshold}cm)";
        }

        return $reason;
    }

    /**
     * Map events to articles
     */
    private function mapEventsToArticles(
        array $surchargeEvents,
        int $carrierId,
        ?int $portId,
        ?string $vehicleCategory,
        ?int $categoryGroupId,
        ?string $vesselName,
        ?string $vesselClass
    ): array {
        $drafts = [];

        foreach ($surchargeEvents as $event) {
            $map = $this->resolver->resolveArticleMap(
                $carrierId,
                $portId,
                $vehicleCategory,
                $categoryGroupId,
                $event['event_code'],
                $vesselName,
                $vesselClass
            );

            if (!$map) {
                continue; // No article mapping found
            }

            $drafts[] = [
                'article_id' => $map->article_id,
                'qty' => $event['qty'],
                'amount_override' => $event['amount'] > 0 ? $event['amount'] : null,
                'meta' => [
                    'event_code' => $event['event_code'],
                    'qty_mode' => $map->qty_mode,
                    'reason' => $event['reason'],
                    'matched_rule_id' => $event['matched_rule_id'],
                ],
            ];
        }

        return $drafts;
    }
}


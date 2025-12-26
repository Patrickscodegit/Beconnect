<?php

namespace App\Services\CarrierRules;

use App\Models\CarrierAcceptanceRule;
use App\Models\CarrierClassificationBand;
use App\Models\CarrierSurchargeArticleMap;
use App\Models\CarrierSurchargeRule;
use App\Models\CarrierTransformRule;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves which rule applies when multiple rules match, using specificity scoring.
 * 
 * Specificity Scoring:
 * - +10: Exact vessel name match (vessel_name matches)
 * - +8: Exact POD match (port_id matches)
 * - +6: Vessel class match (vessel_class matches)
 * - +4: Country match (future: country_code matches)
 * - +2: Trade lane match (future: trade_lane matches)
 * - +2: Exact vehicle category match
 * - +1: Category group match (item category is member of group)
 * - +1: Commodity tag match (using commodity item flags)
 * 
 * Tie-breakers: higher priority, latest effective_from, then id DESC.
 */
class CarrierRuleResolver
{
    /**
     * Resolve classification band for cargo
     */
    public function resolveClassificationBand(
        int $carrierId,
        ?int $portId,
        float $cbm,
        ?float $heightCm = null,
        ?string $vesselName = null,
        ?string $vesselClass = null
    ): ?CarrierClassificationBand {
        $bands = CarrierClassificationBand::where('carrier_id', $carrierId)
            ->active()
            ->where(function ($q) use ($portId) {
                $q->whereNull('port_id')->orWhere('port_id', $portId);
            })
            ->where(function ($q) use ($vesselName) {
                $q->whereNull('vessel_name')->orWhere('vessel_name', $vesselName);
            })
            ->where(function ($q) use ($vesselClass) {
                $q->whereNull('vessel_class')->orWhere('vessel_class', $vesselClass);
            })
            ->get()
            ->filter(fn($band) => $band->matches($cbm, $heightCm));

        if ($bands->isEmpty()) {
            return null;
        }

        return $this->selectMostSpecific($bands, $portId, $vesselName, $vesselClass, null, null);
    }

    /**
     * Resolve acceptance rule
     */
    public function resolveAcceptanceRule(
        int $carrierId,
        ?int $portId,
        ?string $vehicleCategory = null,
        ?int $categoryGroupId = null,
        ?string $vesselName = null,
        ?string $vesselClass = null
    ): ?CarrierAcceptanceRule {
        $rules = CarrierAcceptanceRule::where('carrier_id', $carrierId)
            ->active()
            ->where(function ($q) use ($portId) {
                $q->whereNull('port_id')->orWhere('port_id', $portId);
            })
            ->where(function ($q) use ($vehicleCategory) {
                $q->whereNull('vehicle_category')->orWhere('vehicle_category', $vehicleCategory);
            })
            ->where(function ($q) use ($categoryGroupId) {
                $q->whereNull('category_group_id')->orWhere('category_group_id', $categoryGroupId);
            })
            ->where(function ($q) use ($vesselName) {
                $q->whereNull('vessel_name')->orWhere('vessel_name', $vesselName);
            })
            ->where(function ($q) use ($vesselClass) {
                $q->whereNull('vessel_class')->orWhere('vessel_class', $vesselClass);
            })
            ->get();

        if ($rules->isEmpty()) {
            return null;
        }

        return $this->selectMostSpecific($rules, $portId, $vesselName, $vesselClass, $vehicleCategory, $categoryGroupId);
    }

    /**
     * Resolve transform rules (can return multiple, apply in priority order)
     */
    public function resolveTransformRules(
        int $carrierId,
        ?int $portId,
        ?string $vehicleCategory = null,
        ?int $categoryGroupId = null,
        ?string $vesselName = null,
        ?string $vesselClass = null
    ): Collection {
        return CarrierTransformRule::where('carrier_id', $carrierId)
            ->active()
            ->where(function ($q) use ($portId) {
                $q->whereNull('port_id')->orWhere('port_id', $portId);
            })
            ->where(function ($q) use ($vehicleCategory) {
                $q->whereNull('vehicle_category')->orWhere('vehicle_category', $vehicleCategory);
            })
            ->where(function ($q) use ($categoryGroupId) {
                $q->whereNull('category_group_id')->orWhere('category_group_id', $categoryGroupId);
            })
            ->where(function ($q) use ($vesselName) {
                $q->whereNull('vessel_name')->orWhere('vessel_name', $vesselName);
            })
            ->where(function ($q) use ($vesselClass) {
                $q->whereNull('vessel_class')->orWhere('vessel_class', $vesselClass);
            })
            ->orderBy('priority', 'desc')
            ->orderBy('effective_from', 'desc')
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Resolve surcharge rules
     */
    public function resolveSurchargeRules(
        int $carrierId,
        ?int $portId,
        ?string $vehicleCategory = null,
        ?int $categoryGroupId = null,
        ?string $vesselName = null,
        ?string $vesselClass = null
    ): Collection {
        return CarrierSurchargeRule::where('carrier_id', $carrierId)
            ->active()
            ->where(function ($q) use ($portId) {
                $q->whereNull('port_id')->orWhere('port_id', $portId);
            })
            ->where(function ($q) use ($vehicleCategory) {
                $q->whereNull('vehicle_category')->orWhere('vehicle_category', $vehicleCategory);
            })
            ->where(function ($q) use ($categoryGroupId) {
                $q->whereNull('category_group_id')->orWhere('category_group_id', $categoryGroupId);
            })
            ->where(function ($q) use ($vesselName) {
                $q->whereNull('vessel_name')->orWhere('vessel_name', $vesselName);
            })
            ->where(function ($q) use ($vesselClass) {
                $q->whereNull('vessel_class')->orWhere('vessel_class', $vesselClass);
            })
            ->orderBy('priority', 'desc')
            ->orderBy('effective_from', 'desc')
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Resolve article map for an event code
     */
    public function resolveArticleMap(
        int $carrierId,
        ?int $portId,
        ?string $vehicleCategory,
        ?int $categoryGroupId,
        string $eventCode,
        ?string $vesselName = null,
        ?string $vesselClass = null
    ): ?CarrierSurchargeArticleMap {
        $maps = CarrierSurchargeArticleMap::where('carrier_id', $carrierId)
            ->where('event_code', $eventCode)
            ->active()
            ->where(function ($q) use ($portId) {
                $q->whereNull('port_id')->orWhere('port_id', $portId);
            })
            ->where(function ($q) use ($vehicleCategory) {
                $q->whereNull('vehicle_category')->orWhere('vehicle_category', $vehicleCategory);
            })
            ->where(function ($q) use ($categoryGroupId) {
                $q->whereNull('category_group_id')->orWhere('category_group_id', $categoryGroupId);
            })
            ->where(function ($q) use ($vesselName) {
                $q->whereNull('vessel_name')->orWhere('vessel_name', $vesselName);
            })
            ->where(function ($q) use ($vesselClass) {
                $q->whereNull('vessel_class')->orWhere('vessel_class', $vesselClass);
            })
            ->get();

        if ($maps->isEmpty()) {
            return null;
        }

        return $this->selectMostSpecific($maps, $portId, $vesselName, $vesselClass, $vehicleCategory, $categoryGroupId);
    }

    /**
     * Select most specific rule from collection using specificity scoring
     */
    private function selectMostSpecific(
        Collection $rules,
        ?int $portId,
        ?string $vesselName,
        ?string $vesselClass,
        ?string $vehicleCategory,
        ?int $categoryGroupId
    ) {
        return $rules->map(function ($rule) use ($portId, $vesselName, $vesselClass, $vehicleCategory, $categoryGroupId) {
            $score = 0;

            // +10: Exact vessel name match
            if ($vesselName && $rule->vessel_name === $vesselName) {
                $score += 10;
            }

            // +8: Exact POD match
            if ($portId && $rule->port_id === $portId) {
                $score += 8;
            }

            // +6: Vessel class match
            if ($vesselClass && $rule->vessel_class === $vesselClass) {
                $score += 6;
            }

            // +2: Exact vehicle category match
            if ($vehicleCategory && $rule->vehicle_category === $vehicleCategory) {
                $score += 2;
            }

            // +1: Category group match
            if ($categoryGroupId && $rule->category_group_id === $categoryGroupId) {
                $score += 1;
            }

            return [
                'rule' => $rule,
                'score' => $score,
                'priority' => $rule->priority ?? 0,
                'effective_from' => $rule->effective_from,
                'id' => $rule->id,
            ];
        })
        ->sortBy([
            ['score', 'desc'],
            ['priority', 'desc'],
            ['effective_from', 'desc'],
            ['id', 'desc'],
        ])
        ->first()['rule'] ?? null;
    }
}


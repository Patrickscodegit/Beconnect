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
                // Global rule: both legacy and array are NULL
                $q->where(function ($q2) {
                    $q2->whereNull('port_id')->whereNull('port_ids');
                });
                
                // If input known, match legacy OR array
                if ($portId !== null) {
                    $q->orWhere('port_id', $portId)
                      ->orWhereJsonContains('port_ids', $portId);
                }
            })
            ->where(function ($q) use ($vesselName) {
                $q->where(function ($q2) {
                    $q2->whereNull('vessel_name')->whereNull('vessel_names');
                });
                
                if ($vesselName !== null) {
                    $q->orWhere('vessel_name', $vesselName)
                      ->orWhereJsonContains('vessel_names', $vesselName);
                }
            })
            ->where(function ($q) use ($vesselClass) {
                $q->where(function ($q2) {
                    $q2->whereNull('vessel_class')->whereNull('vessel_classes');
                });
                
                if ($vesselClass !== null) {
                    $q->orWhere('vessel_class', $vesselClass)
                      ->orWhereJsonContains('vessel_classes', $vesselClass);
                }
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
                // Global rule: both legacy and array are NULL
                $q->where(function ($q2) {
                    $q2->whereNull('port_id')->whereNull('port_ids');
                });
                
                // If input known, match legacy OR array
                if ($portId !== null) {
                    $q->orWhere('port_id', $portId)
                      ->orWhereJsonContains('port_ids', $portId);
                }
            })
            ->where(function ($q) use ($vehicleCategory) {
                $q->where(function ($q2) {
                    $q2->whereNull('vehicle_category')->whereNull('vehicle_categories');
                });
                
                if ($vehicleCategory !== null) {
                    $q->orWhere('vehicle_category', $vehicleCategory)
                      ->orWhereJsonContains('vehicle_categories', $vehicleCategory);
                }
            })
            ->where(function($q) use ($categoryGroupId) {
                $q->whereNull('category_group_id');
                if ($categoryGroupId !== null) {
                    $q->orWhere('category_group_id', $categoryGroupId);
                }
            })
            ->where(function ($q) use ($vesselName) {
                $q->where(function ($q2) {
                    $q2->whereNull('vessel_name')->whereNull('vessel_names');
                });
                
                if ($vesselName !== null) {
                    $q->orWhere('vessel_name', $vesselName)
                      ->orWhereJsonContains('vessel_names', $vesselName);
                }
            })
            ->where(function ($q) use ($vesselClass) {
                $q->where(function ($q2) {
                    $q2->whereNull('vessel_class')->whereNull('vessel_classes');
                });
                
                if ($vesselClass !== null) {
                    $q->orWhere('vessel_class', $vesselClass)
                      ->orWhereJsonContains('vessel_classes', $vesselClass);
                }
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
                $q->where(function ($q2) {
                    $q2->whereNull('port_id')->whereNull('port_ids');
                });
                
                if ($portId !== null) {
                    $q->orWhere('port_id', $portId)
                      ->orWhereJsonContains('port_ids', (string)$portId)
                      ->orWhereJsonContains('port_ids', (int)$portId);
                }
            })
            ->where(function ($q) use ($vehicleCategory) {
                $q->where(function ($q2) {
                    $q2->whereNull('vehicle_category')->whereNull('vehicle_categories');
                });
                
                if ($vehicleCategory !== null) {
                    $q->orWhere('vehicle_category', $vehicleCategory)
                      ->orWhereJsonContains('vehicle_categories', $vehicleCategory);
                }
            })
            ->where(function($q) use ($categoryGroupId) {
                $q->whereNull('category_group_id');
                if ($categoryGroupId !== null) {
                    $q->orWhere('category_group_id', $categoryGroupId);
                }
            })
            ->where(function ($q) use ($vesselName) {
                $q->where(function ($q2) {
                    $q2->whereNull('vessel_name')->whereNull('vessel_names');
                });
                
                if ($vesselName !== null) {
                    $q->orWhere('vessel_name', $vesselName)
                      ->orWhereJsonContains('vessel_names', $vesselName);
                }
            })
            ->where(function ($q) use ($vesselClass) {
                $q->where(function ($q2) {
                    $q2->whereNull('vessel_class')->whereNull('vessel_classes');
                });
                
                if ($vesselClass !== null) {
                    $q->orWhere('vessel_class', $vesselClass)
                      ->orWhereJsonContains('vessel_classes', $vesselClass);
                }
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
                $q->where(function ($q2) {
                    $q2->whereNull('port_id')->whereNull('port_ids');
                });
                
                if ($portId !== null) {
                    $q->orWhere('port_id', $portId)
                      ->orWhereJsonContains('port_ids', (string)$portId)
                      ->orWhereJsonContains('port_ids', (int)$portId);
                }
            })
            ->where(function ($q) use ($vehicleCategory) {
                $q->where(function ($q2) {
                    $q2->whereNull('vehicle_category')->whereNull('vehicle_categories');
                });
                
                if ($vehicleCategory !== null) {
                    $q->orWhere('vehicle_category', $vehicleCategory)
                      ->orWhereJsonContains('vehicle_categories', $vehicleCategory);
                }
            })
            ->where(function($q) use ($categoryGroupId) {
                $q->whereNull('category_group_id');
                if ($categoryGroupId !== null) {
                    $q->orWhere('category_group_id', $categoryGroupId);
                }
            })
            ->where(function ($q) use ($vesselName) {
                $q->where(function ($q2) {
                    $q2->whereNull('vessel_name')->whereNull('vessel_names');
                });
                
                if ($vesselName !== null) {
                    $q->orWhere('vessel_name', $vesselName)
                      ->orWhereJsonContains('vessel_names', $vesselName);
                }
            })
            ->where(function ($q) use ($vesselClass) {
                $q->where(function ($q2) {
                    $q2->whereNull('vessel_class')->whereNull('vessel_classes');
                });
                
                if ($vesselClass !== null) {
                    $q->orWhere('vessel_class', $vesselClass)
                      ->orWhereJsonContains('vessel_classes', $vesselClass);
                }
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
                $q->where(function ($q2) {
                    $q2->whereNull('port_id')->whereNull('port_ids');
                });
                
                if ($portId !== null) {
                    $q->orWhere('port_id', $portId)
                      ->orWhereJsonContains('port_ids', (string)$portId)
                      ->orWhereJsonContains('port_ids', (int)$portId);
                }
            })
            ->where(function ($q) use ($vehicleCategory) {
                $q->where(function ($q2) {
                    $q2->whereNull('vehicle_category')->whereNull('vehicle_categories');
                });
                
                if ($vehicleCategory !== null) {
                    $q->orWhere('vehicle_category', $vehicleCategory)
                      ->orWhereJsonContains('vehicle_categories', $vehicleCategory);
                }
            })
            ->where(function($q) use ($categoryGroupId) {
                $q->whereNull('category_group_id');
                if ($categoryGroupId !== null) {
                    $q->orWhere('category_group_id', $categoryGroupId);
                }
            })
            ->where(function ($q) use ($vesselName) {
                $q->where(function ($q2) {
                    $q2->whereNull('vessel_name')->whereNull('vessel_names');
                });
                
                if ($vesselName !== null) {
                    $q->orWhere('vessel_name', $vesselName)
                      ->orWhereJsonContains('vessel_names', $vesselName);
                }
            })
            ->where(function ($q) use ($vesselClass) {
                $q->where(function ($q2) {
                    $q2->whereNull('vessel_class')->whereNull('vessel_classes');
                });
                
                if ($vesselClass !== null) {
                    $q->orWhere('vessel_class', $vesselClass)
                      ->orWhereJsonContains('vessel_classes', $vesselClass);
                }
            })
            ->get();

        if ($maps->isEmpty()) {
            return null;
        }

        return $this->selectMostSpecific($maps, $portId, $vesselName, $vesselClass, $vehicleCategory, $categoryGroupId);
    }

    /**
     * Select most specific rule from collection using specificity scoring
     * MICRO-FIX: Only score when rule is scoped
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
            
            // +10: Vessel name match (only if rule is vessel-name scoped AND matches)
            if ($vesselName && method_exists($rule, 'isVesselNameScoped') && $rule->isVesselNameScoped() && $rule->matchesVesselName($vesselName)) {
                $score += 10;
            }
            
            // +8: Port match (only if rule is port-scoped AND matches)
            if ($portId && method_exists($rule, 'isPortScoped') && $rule->isPortScoped() && $rule->matchesPort($portId)) {
                $score += 8;
            }
            
            // +6: Vessel class match (only if rule is vessel-class scoped AND matches)
            if ($vesselClass && method_exists($rule, 'isVesselClassScoped') && $rule->isVesselClassScoped() && $rule->matchesVesselClass($vesselClass)) {
                $score += 6;
            }
            
            // +2: Vehicle category match (only if rule is category-scoped AND matches)
            if ($vehicleCategory && method_exists($rule, 'isCategoryScoped') && $rule->isCategoryScoped() && $rule->matchesVehicleCategory($vehicleCategory)) {
                $score += 2;
            }
            
            // +1: Category group match
            if ($categoryGroupId && isset($rule->category_group_id) && (int)$rule->category_group_id === (int)$categoryGroupId) {
                $score += 1;
            }
            
            // Effective sort key with NULL LAST (micro-fix: use timestamp)
            $effectiveTs = $rule->effective_from ? $rule->effective_from->timestamp : -1;
            
            return [
                'rule' => $rule,
                'score' => $score,
                'priority' => $rule->priority ?? 0,
                'effective_ts' => $effectiveTs,
                'id' => $rule->id,
            ];
        })
        ->sortBy([
            ['score', 'desc'],
            ['priority', 'desc'],
            ['effective_ts', 'desc'],
            ['id', 'desc'],
        ])
        ->first()['rule'] ?? null;
    }
}


<?php

namespace App\Services\CarrierRules;

use App\Models\CarrierAcceptanceRule;
use App\Models\CarrierArticleMapping;
use App\Models\CarrierPortGroupMember;
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
     * Resolve port group IDs for a given port (performance optimization: call once per query)
     */
    public function resolvePortGroupIdsForPort(int $carrierId, int $portId): array
    {
        return CarrierPortGroupMember::query()
            ->join('carrier_port_groups', 'carrier_port_group_members.carrier_port_group_id', '=', 'carrier_port_groups.id')
            ->where('carrier_port_groups.carrier_id', $carrierId)
            ->where('carrier_port_group_members.port_id', $portId)
            ->where('carrier_port_group_members.is_active', true)
            ->where('carrier_port_groups.is_active', true)
            ->where(function ($q) {
                $q->whereNull('carrier_port_groups.effective_from')
                  ->orWhere('carrier_port_groups.effective_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('carrier_port_groups.effective_to')
                  ->orWhere('carrier_port_groups.effective_to', '>=', now());
            })
            ->pluck('carrier_port_group_members.carrier_port_group_id')
            ->map(fn($id) => (int)$id)
            ->toArray();
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
        // Resolve port group IDs once if portId is provided
        $portGroupIds = [];
        if ($portId !== null) {
            $portGroupIds = $this->resolvePortGroupIdsForPort($carrierId, $portId);
            // Convert to strings since JSON stores them as strings
            $portGroupIds = array_map('strval', $portGroupIds);
        }

        $rules = CarrierAcceptanceRule::where('carrier_id', $carrierId)
            ->active()
            ->where(function ($q) use ($portId, $portGroupIds) {
                // Global rule: port_id, port_ids, and port_group_ids are all NULL
                $q->where(function ($q2) {
                    $q2->whereNull('port_id')
                       ->whereNull('port_ids')
                       ->whereNull('port_group_ids');
                });
                
                // If input known, match legacy OR array OR port groups
                if ($portId !== null) {
                    $q->orWhere('port_id', $portId)
                      ->orWhereJsonContains('port_ids', $portId);
                    
                    // Port groups (if any found)
                    // portGroupIds are already converted to strings above
                    if (!empty($portGroupIds)) {
                        foreach ($portGroupIds as $groupId) {
                            $q->orWhereJsonContains('port_group_ids', $groupId);
                        }
                    }
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
                // If categoryGroupId is null, match all rules (don't filter by category group)
                if ($categoryGroupId === null) {
                    $q->whereRaw('1 = 1'); // Always true - match all
                } else {
                    // Match rules with null category_group OR rules that match the provided categoryGroupId
                    $q->where(function ($q2) {
                        $q2->whereNull('category_group_id')->whereNull('category_group_ids');
                    })
                    ->orWhere('category_group_id', $categoryGroupId)
                    ->orWhereJsonContains('category_group_ids', (string)$categoryGroupId)
                    ->orWhereJsonContains('category_group_ids', (int)$categoryGroupId);
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
            // Safety net: filter out invalid rules where min > max
            ->filter(function ($rule) {
                // Check length
                if ($rule->min_length_cm !== null && $rule->max_length_cm !== null && $rule->min_length_cm > $rule->max_length_cm) {
                    return false;
                }
                // Check width
                if ($rule->min_width_cm !== null && $rule->max_width_cm !== null && $rule->min_width_cm > $rule->max_width_cm) {
                    return false;
                }
                // Check height
                if ($rule->min_height_cm !== null && $rule->max_height_cm !== null && $rule->min_height_cm > $rule->max_height_cm) {
                    return false;
                }
                // Check CBM
                if ($rule->min_cbm !== null && $rule->max_cbm !== null && $rule->min_cbm > $rule->max_cbm) {
                    return false;
                }
                // Check weight
                if ($rule->min_weight_kg !== null && $rule->max_weight_kg !== null && $rule->min_weight_kg > $rule->max_weight_kg) {
                    return false;
                }
                return true;
            });

        if ($rules->isEmpty()) {
            return null;
        }

        return $this->selectMostSpecific($rules, $portId, $vesselName, $vesselClass, $vehicleCategory, $categoryGroupId, $portGroupIds);
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
        // Resolve port group IDs once if portId is provided
        $portGroupIds = [];
        if ($portId !== null) {
            $portGroupIds = $this->resolvePortGroupIdsForPort($carrierId, $portId);
        }

        $query = CarrierTransformRule::where('carrier_id', $carrierId)
            ->active()
            ->where(function ($q) use ($portId, $portGroupIds) {
                $q->where(function ($q2) {
                    $q2->whereNull('port_id')
                       ->whereNull('port_ids')
                       ->whereNull('port_group_ids');
                });
                
                if ($portId !== null) {
                    $q->orWhere('port_id', $portId)
                      ->orWhereJsonContains('port_ids', (string)$portId)
                      ->orWhereJsonContains('port_ids', (int)$portId);
                    
                    // Port groups (if any found)
                    if (!empty($portGroupIds)) {
                        foreach ($portGroupIds as $groupId) {
                            $q->orWhereJsonContains('port_group_ids', $groupId)
                              ->orWhereJsonContains('port_group_ids', (string)$groupId);
                        }
                    }
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
                // If categoryGroupId is null, match all rules (no filtering by category group)
                // If categoryGroupId is set, match rules with null category_group OR matching category_group
                if ($categoryGroupId === null) {
                    // No filtering - match all rules regardless of category_group_id/category_group_ids
                    $q->whereRaw('1 = 1'); // Always true
                } else {
                    // Match rules with null category_group OR rules that match the provided categoryGroupId
                    $q->where(function ($q2) {
                        $q2->whereNull('category_group_id')->whereNull('category_group_ids');
                    })
                    ->orWhere('category_group_id', $categoryGroupId)
                    ->orWhereJsonContains('category_group_ids', (string)$categoryGroupId)
                    ->orWhereJsonContains('category_group_ids', (int)$categoryGroupId);
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
            ->orderBy('id', 'desc');
        
        return $query->get();
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
        // Resolve port group IDs once if portId is provided
        $portGroupIds = [];
        if ($portId !== null) {
            $portGroupIds = $this->resolvePortGroupIdsForPort($carrierId, $portId);
        }

        return CarrierSurchargeRule::where('carrier_id', $carrierId)
            ->active()
            ->where(function ($q) use ($portId, $portGroupIds) {
                $q->where(function ($q2) {
                    $q2->whereNull('port_id')
                       ->whereNull('port_ids')
                       ->whereNull('port_group_ids');
                });
                
                if ($portId !== null) {
                    $q->orWhere('port_id', $portId)
                      ->orWhereJsonContains('port_ids', (string)$portId)
                      ->orWhereJsonContains('port_ids', (int)$portId);
                    
                    // Port groups (if any found)
                    if (!empty($portGroupIds)) {
                        foreach ($portGroupIds as $groupId) {
                            $q->orWhereJsonContains('port_group_ids', $groupId);
                        }
                    }
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
                // If categoryGroupId is null, match all rules (don't filter by category group)
                if ($categoryGroupId === null) {
                    $q->whereRaw('1 = 1'); // Always true - match all
                } else {
                    // Match rules with null category_group OR rules that match the provided categoryGroupId
                    $q->where(function ($q2) {
                        $q2->whereNull('category_group_id')->whereNull('category_group_ids');
                    })
                    ->orWhere('category_group_id', $categoryGroupId)
                    ->orWhereJsonContains('category_group_ids', (string)$categoryGroupId)
                    ->orWhereJsonContains('category_group_ids', (int)$categoryGroupId);
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
        // Resolve port group IDs once if portId is provided
        $portGroupIds = [];
        if ($portId !== null) {
            $portGroupIds = $this->resolvePortGroupIdsForPort($carrierId, $portId);
        }

        $maps = CarrierSurchargeArticleMap::where('carrier_id', $carrierId)
            ->where('event_code', $eventCode)
            ->active()
            ->where(function ($q) use ($portId, $portGroupIds) {
                $q->where(function ($q2) {
                    $q2->whereNull('port_id')
                       ->whereNull('port_ids')
                       ->whereNull('port_group_ids');
                });
                
                if ($portId !== null) {
                    $q->orWhere('port_id', $portId)
                      ->orWhereJsonContains('port_ids', (string)$portId)
                      ->orWhereJsonContains('port_ids', (int)$portId);
                    
                    // Port groups (if any found)
                    if (!empty($portGroupIds)) {
                        foreach ($portGroupIds as $groupId) {
                            $q->orWhereJsonContains('port_group_ids', $groupId);
                        }
                    }
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
                // If categoryGroupId is null, match all rules (don't filter by category group)
                if ($categoryGroupId === null) {
                    $q->whereRaw('1 = 1'); // Always true - match all
                } else {
                    // Match rules with null category_group OR rules that match the provided categoryGroupId
                    $q->where(function ($q2) {
                        $q2->whereNull('category_group_id')->whereNull('category_group_ids');
                    })
                    ->orWhere('category_group_id', $categoryGroupId)
                    ->orWhereJsonContains('category_group_ids', (string)$categoryGroupId)
                    ->orWhereJsonContains('category_group_ids', (int)$categoryGroupId);
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

        return $this->selectMostSpecific($maps, $portId, $vesselName, $vesselClass, $vehicleCategory, $categoryGroupId, $portGroupIds);
    }

    /**
     * Resolve article mappings for a given quotation context (UNION behavior - returns all matching mappings)
     */
    public function resolveArticleMappings(
        int $carrierId,
        ?int $portId,
        ?string $vehicleCategory,
        ?int $categoryGroupId,
        ?string $vesselName = null,
        ?string $vesselClass = null
    ): Collection {
        // Resolve port group IDs ONCE if portId is not null
        $inputPortGroupIds = [];
        if ($portId !== null) {
            $inputPortGroupIds = $this->resolvePortGroupIdsForPort($carrierId, $portId);
        }

        $query = CarrierArticleMapping::query()
            ->where('carrier_id', $carrierId)
            ->active();

        // Apply port scoping
        $query->where(function ($q) use ($portId, $inputPortGroupIds) {
            // Global rules (no port scope)
            $q->where(function ($q2) {
                $q2->whereNull('port_ids')
                   ->whereNull('port_group_ids');
            });

            if ($portId !== null) {
                // Port-specific rules
                $q->orWhereJsonContains('port_ids', (string)$portId)
                  ->orWhereJsonContains('port_ids', (int)$portId);

                // Port group rules (overlap check)
                if (!empty($inputPortGroupIds)) {
                    foreach ($inputPortGroupIds as $groupId) {
                        $q->orWhereJsonContains('port_group_ids', $groupId);
                    }
                }
            }
        });

        // Apply vehicle category scoping
        $query->where(function ($q) use ($vehicleCategory) {
            // Global rules (no vehicle category scope)
            $q->whereNull('vehicle_categories');

            if ($vehicleCategory !== null) {
                // Vehicle category-specific rules
                $q->orWhereJsonContains('vehicle_categories', $vehicleCategory);
            }
        });

        // Apply category group scoping
        $query->where(function ($q) use ($categoryGroupId) {
            // Global rules (no category group scope)
            $q->whereNull('category_group_ids');

            if ($categoryGroupId !== null) {
                // Category group-specific rules (type-safe)
                $q->orWhereJsonContains('category_group_ids', (string)$categoryGroupId)
                  ->orWhereJsonContains('category_group_ids', (int)$categoryGroupId);
            }
        });

        // Apply vessel name scoping
        $query->where(function ($q) use ($vesselName) {
            // Global rules (no vessel name scope)
            $q->whereNull('vessel_names');

            if ($vesselName !== null) {
                // Vessel name-specific rules
                $q->orWhereJsonContains('vessel_names', $vesselName);
            }
        });

        // Apply vessel class scoping
        $query->where(function ($q) use ($vesselClass) {
            // Global rules (no vessel class scope)
            $q->whereNull('vessel_classes');

            if ($vesselClass !== null) {
                // Vessel class-specific rules
                $q->orWhereJsonContains('vessel_classes', $vesselClass);
            }
        });

        // Return ALL matching mappings (UNION - do not use selectMostSpecific)
        return $query->get();
    }

    /**
     * Select most specific rule from collection using specificity scoring
     * MICRO-FIX: Only score when rule is scoped
     */
    public function selectMostSpecific(
        Collection $rules,
        ?int $portId,
        ?string $vesselName,
        ?string $vesselClass,
        ?string $vehicleCategory,
        ?int $categoryGroupId,
        array $portGroupIds = []
    ) {
        $scored = $rules->map(function ($rule) use ($portId, $vesselName, $vesselClass, $vehicleCategory, $categoryGroupId, $portGroupIds) {
            $score = 0;
            $scoreDetails = [];
            
            // +10: Vessel name match (only if rule is vessel-name scoped AND matches)
            if ($vesselName && method_exists($rule, 'isVesselNameScoped') && $rule->isVesselNameScoped() && $rule->matchesVesselName($vesselName)) {
                $score += 10;
                $scoreDetails[] = 'vessel_name:+10';
            }
            
            // +8: Direct port match (port_id or port_ids matches directly)
            // +6: Port group match (port_group_ids matches)
            if ($portId && method_exists($rule, 'isPortScoped') && $rule->isPortScoped()) {
                // Check for direct port match first (more specific)
                $hasDirectPortMatch = false;
                if (!empty($rule->port_ids)) {
                    $portIdsInt = array_map('intval', $rule->port_ids);
                    $hasDirectPortMatch = in_array((int)$portId, $portIdsInt, true);
                } elseif (!is_null($rule->port_id)) {
                    $hasDirectPortMatch = (int)$rule->port_id === (int)$portId;
                }
                
                // Check for port group match (less specific)
                $hasPortGroupMatch = false;
                if (!empty($rule->port_group_ids) && !empty($portGroupIds)) {
                    $ruleGroupIds = array_map('intval', $rule->port_group_ids);
                    $hasPortGroupMatch = !empty(array_intersect($ruleGroupIds, $portGroupIds));
                }
                
                if ($hasDirectPortMatch) {
                    $score += 8;
                    $scoreDetails[] = 'port_direct:+8';
                } elseif ($hasPortGroupMatch) {
                    $score += 6;
                    $scoreDetails[] = 'port_group:+6';
                }
            }
            
            // +6: Vessel class match (only if rule is vessel-class scoped AND matches)
            if ($vesselClass && method_exists($rule, 'isVesselClassScoped') && $rule->isVesselClassScoped() && $rule->matchesVesselClass($vesselClass)) {
                $score += 6;
                $scoreDetails[] = 'vessel_class:+6';
            }
            
            // +2: Vehicle category match (only if rule is category-scoped AND matches)
            if ($vehicleCategory && method_exists($rule, 'isCategoryScoped') && $rule->isCategoryScoped() && $rule->matchesVehicleCategory($vehicleCategory)) {
                $score += 2;
                $scoreDetails[] = 'vehicle_category:+2';
            }
            
            // +3: Category group match (only if rule is category-group scoped AND matches)
            // Higher weight because category groups are more specific than vehicle categories
            if ($categoryGroupId && method_exists($rule, 'isCategoryGroupScoped') && $rule->isCategoryGroupScoped() && $rule->matchesCategoryGroup($categoryGroupId)) {
                $score += 3;
                $scoreDetails[] = 'category_group:+3';
            }
            
            // Effective sort key with NULL LAST (micro-fix: use timestamp)
            $effectiveTs = $rule->effective_from ? $rule->effective_from->timestamp : -1;
            
            return [
                'rule' => $rule,
                'score' => $score,
                'priority' => $rule->priority ?? 0,
                'effective_ts' => $effectiveTs,
                'id' => $rule->id,
                'score_details' => $scoreDetails,
            ];
        })
        ->sortBy([
            ['score', 'desc'],
            ['priority', 'desc'],
            ['effective_ts', 'desc'],
            ['id', 'desc'],
        ]);
        
        return $scored->first()['rule'] ?? null;
    }
}


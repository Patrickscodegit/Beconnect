<?php

namespace App\Models\Concerns;

trait HasMultiScopeMatches
{
    /**
     * Check if port matches this rule's scope
     * Supports both individual ports (port_id, port_ids) and port groups (port_group_ids)
     * If both are set, matches if EITHER individual ports OR port groups match (OR logic)
     * If input is null: scoped rules DO NOT match (only global matches)
     * 
     * @param int|null $portId The port ID to check
     * @param array $inputPortGroupIds Pre-resolved port group IDs for the port (to avoid N+1 queries)
     */
    public function matchesPort(?int $portId, array $inputPortGroupIds = []): bool
    {
        $hasIndividualScope = !empty($this->port_ids) || !is_null($this->port_id);
        $hasGroupScope = !empty($this->port_group_ids);
        
        // Global rule
        if (!$hasIndividualScope && !$hasGroupScope) {
            return true;
        }
        
        // Scoped rules don't match null input
        if ($portId === null) {
            return false;
        }
        
        // Check individual ports
        $matchesIndividual = false;
        if ($hasIndividualScope) {
            if (!empty($this->port_ids)) {
                $portIdsInt = array_map('intval', $this->port_ids);
                $matchesIndividual = in_array((int)$portId, $portIdsInt, true);
            } elseif (!is_null($this->port_id)) {
                $matchesIndividual = (int)$this->port_id === (int)$portId;
            }
        }
        
        // Check port groups (using pre-resolved IDs)
        $matchesGroup = false;
        if ($hasGroupScope && !empty($inputPortGroupIds)) {
            $ruleGroupIds = array_map('intval', $this->port_group_ids);
            $matchesGroup = !empty(array_intersect($ruleGroupIds, $inputPortGroupIds));
        }
        
        // OR logic: matches if either individual OR group matches
        if ($hasIndividualScope && $hasGroupScope) {
            return $matchesIndividual || $matchesGroup;
        }
        
        if ($hasIndividualScope) {
            return $matchesIndividual;
        }
        
        return $matchesGroup;
    }

    /**
     * Check if vehicle category matches this rule's scope
     */
    public function matchesVehicleCategory(?string $category): bool
    {
        if (!empty($this->vehicle_categories)) {
            return $category !== null && in_array($category, $this->vehicle_categories, true);
        }
        if (!is_null($this->vehicle_category)) {
            return $category !== null && $this->vehicle_category === $category;
        }
        return true; // Global rule
    }

    /**
     * Check if vessel name matches this rule's scope
     */
    public function matchesVesselName(?string $vesselName): bool
    {
        if (!empty($this->vessel_names)) {
            return $vesselName !== null && in_array($vesselName, $this->vessel_names, true);
        }
        if (!is_null($this->vessel_name)) {
            return $vesselName !== null && $this->vessel_name === $vesselName;
        }
        return true; // Global rule
    }

    /**
     * Check if vessel class matches this rule's scope
     */
    public function matchesVesselClass(?string $vesselClass): bool
    {
        if (!empty($this->vessel_classes)) {
            return $vesselClass !== null && in_array($vesselClass, $this->vessel_classes, true);
        }
        if (!is_null($this->vessel_class)) {
            return $vesselClass !== null && $this->vessel_class === $vesselClass;
        }
        return true; // Global rule
    }

    /**
     * Check if category group matches this rule's scope
     */
    public function matchesCategoryGroup(?int $categoryGroupId): bool
    {
        if (!empty($this->category_group_ids)) {
            // Convert both to integers for comparison (handles string "4" vs int 4)
            $ruleGroupIdsInt = array_map('intval', $this->category_group_ids);
            return $categoryGroupId !== null && in_array((int)$categoryGroupId, $ruleGroupIdsInt, true);
        }
        if (!is_null($this->category_group_id)) {
            return $categoryGroupId !== null && (int)$this->category_group_id === (int)$categoryGroupId;
        }
        return true; // Global rule
    }

    /**
     * Helpers to detect scoping (used for scoring)
     */
    public function isPortScoped(): bool
    {
        return !empty($this->port_ids) || !is_null($this->port_id) || !empty($this->port_group_ids);
    }

    public function isCategoryScoped(): bool
    {
        return property_exists($this, 'vehicle_categories') && (!empty($this->vehicle_categories) || !is_null($this->vehicle_category));
    }

    public function isVesselNameScoped(): bool
    {
        return !empty($this->vessel_names) || !is_null($this->vessel_name);
    }

    public function isVesselClassScoped(): bool
    {
        return !empty($this->vessel_classes) || !is_null($this->vessel_class);
    }

    public function isCategoryGroupScoped(): bool
    {
        return !empty($this->category_group_ids) || !is_null($this->category_group_id);
    }
}



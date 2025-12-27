<?php

namespace App\Models\Concerns;

trait HasMultiScopeMatches
{
    /**
     * Check if port matches this rule's scope
     * If array scope is set: match only if input is in array
     * Else if legacy single scope is set: match only if input equals it
     * Else: global match (true)
     * If input is null: scoped rules DO NOT match (only global matches)
     */
    public function matchesPort(?int $portId): bool
    {
        if (!empty($this->port_ids)) {
            // Normalize port_ids array and portId to integers for comparison
            $portIdsInt = array_map('intval', $this->port_ids);
            return $portId !== null && in_array((int)$portId, $portIdsInt, true);
        }
        if (!is_null($this->port_id)) {
            return $portId !== null && (int)$this->port_id === (int)$portId;
        }
        return true; // Global rule
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
     * Helpers to detect scoping (used for scoring)
     */
    public function isPortScoped(): bool
    {
        return !empty($this->port_ids) || !is_null($this->port_id);
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
}



<?php

namespace App\Services\CarrierRules;

use App\Models\CarrierAcceptanceRule;
use App\Models\CarrierPortGroupMember;
use App\Models\RobawsArticleCache;
use App\Services\CarrierRules\CarrierRuleResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MaxDimensionsSyncService
{
    private CarrierRuleResolver $resolver;

    public function __construct(CarrierRuleResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Sync acceptance rule to all affected articles
     * Called from model events when rule is saved or deleted
     */
    public function syncRuleToArticles(CarrierAcceptanceRule $rule): void
    {
        $articles = $this->getAffectedArticlesForRule($rule);
        
        foreach ($articles as $article) {
            $this->syncActiveRuleForArticle($article);
        }
    }

    /**
     * Sync the most specific active rule for an article
     */
    public function syncActiveRuleForArticle(RobawsArticleCache $article): void
    {
        // Skip if article doesn't have required fields
        if (!$article->shipping_carrier_id) {
            return;
        }

        // Map commodity_type to vehicle_category
        $vehicleCategory = $this->mapCommodityTypeToVehicleCategory($article->commodity_type);

        // Resolve the most specific acceptance rule
        $rule = $this->resolver->resolveAcceptanceRule(
            $article->shipping_carrier_id,
            $article->pod_port_id,
            $vehicleCategory,
            null, // categoryGroupId - can be enhanced later
            null, // vesselName - can be enhanced later
            null  // vesselClass - can be enhanced later
        );

        if ($rule) {
            // Build detailed breakdown
            $breakdown = $this->buildMaxDimensionsBreakdown($rule, $article);

            // Update article (use updateQuietly with explicit field list to prevent triggering model events and only update intended fields)
            $article->updateQuietly([
                'max_dimensions_breakdown' => $breakdown,
            ]);
        } else {
            // No active rule, clear max dimensions data
            $this->clearMaxDimensionsForArticle($article);
        }
    }

    /**
     * Clear max dimensions data for an article
     */
    public function clearMaxDimensionsForArticle(RobawsArticleCache $article): void
    {
        $article->updateQuietly([
            'max_dimensions_breakdown' => null,
        ]);
    }

    /**
     * Build detailed max dimensions breakdown structure
     */
    public function buildMaxDimensionsBreakdown(CarrierAcceptanceRule $rule, RobawsArticleCache $article): array
    {
        $carrier = $rule->carrier;
        $port = $rule->port;

        $breakdown = [
            'max_length_cm' => $rule->max_length_cm ? (float) $rule->max_length_cm : null,
            'max_width_cm' => $rule->max_width_cm ? (float) $rule->max_width_cm : null,
            'max_height_cm' => $rule->max_height_cm ? (float) $rule->max_height_cm : null,
            'max_weight_kg' => $rule->max_weight_kg ? (float) $rule->max_weight_kg : null,
            'max_cbm' => $rule->max_cbm ? (float) $rule->max_cbm : null,
            'carrier_id' => $carrier ? $carrier->id : null,
            'carrier_name' => $carrier ? $carrier->name : null,
            'port_id' => $port ? $port->id : null,
            'port_name' => $port ? $port->name : null,
            'vehicle_category' => $rule->vehicle_category,
            'rule_id' => $rule->id,
            'effective_from' => $rule->effective_from ? $rule->effective_from->format('Y-m-d') : null,
            'effective_to' => $rule->effective_to ? $rule->effective_to->format('Y-m-d') : null,
            'last_synced_at' => Carbon::now()->toIso8601String(),
            'update_date' => $article->effective_update_date ? $article->effective_update_date->format('Y-m-d') : null,
            'validity_date' => $article->effective_validity_date ? $article->effective_validity_date->format('Y-m-d') : null,
        ];

        return $breakdown;
    }

    /**
     * Map article commodity_type to vehicle_category for acceptance rules
     */
    private function mapCommodityTypeToVehicleCategory(?string $commodityType): ?string
    {
        if (empty($commodityType)) {
            return null;
        }

        $mapping = [
            'Car' => 'car',
            'Small Van' => 'small_van',
            'Big Van' => 'big_van',
            'SUV' => 'suv',
            'Truck' => 'truck',
            'Bus' => 'bus',
            'LM Cargo' => 'truck', // Default to truck, could be more specific
            'Motorcycle' => 'motorcycle',
        ];

        return $mapping[$commodityType] ?? strtolower(str_replace(' ', '_', $commodityType));
    }

    /**
     * Get all articles that match a rule's criteria
     * Used when rule is saved/deleted to sync affected articles
     */
    public function getAffectedArticlesForRule(CarrierAcceptanceRule $rule): Collection
    {
        $query = RobawsArticleCache::query()
            ->where('shipping_carrier_id', $rule->carrier_id)
            ->whereNotNull('commodity_type');

        // Filter by POD port if rule has port scope
        if ($rule->port_id) {
            $query->where('pod_port_id', $rule->port_id);
        } elseif ($rule->port_ids && is_array($rule->port_ids) && !empty($rule->port_ids)) {
            $query->whereIn('pod_port_id', $rule->port_ids);
        } elseif ($rule->port_group_ids && is_array($rule->port_group_ids) && !empty($rule->port_group_ids)) {
            // Resolve port IDs from port groups
            $portIdsFromGroups = $this->getPortIdsFromPortGroups($rule->carrier_id, $rule->port_group_ids);
            if (!empty($portIdsFromGroups)) {
                $query->whereIn('pod_port_id', $portIdsFromGroups);
            }
        }

        // Filter by vehicle category if rule has vehicle category scope
        if ($rule->vehicle_category) {
            $commodityTypes = $this->getCommodityTypesForVehicleCategory($rule->vehicle_category);
            if (!empty($commodityTypes)) {
                $query->whereIn('commodity_type', $commodityTypes);
            }
        } elseif ($rule->vehicle_categories && is_array($rule->vehicle_categories) && !empty($rule->vehicle_categories)) {
            $commodityTypes = [];
            foreach ($rule->vehicle_categories as $vehicleCategory) {
                $types = $this->getCommodityTypesForVehicleCategory($vehicleCategory);
                $commodityTypes = array_merge($commodityTypes, $types);
            }
            if (!empty($commodityTypes)) {
                $query->whereIn('commodity_type', array_unique($commodityTypes));
            }
        }

        return $query->get();
    }

    /**
     * Get commodity types that map to a vehicle category
     * Reverse mapping of mapCommodityTypeToVehicleCategory
     */
    private function getCommodityTypesForVehicleCategory(string $vehicleCategory): array
    {
        $reverseMapping = [
            'car' => ['Car'],
            'small_van' => ['Small Van'],
            'big_van' => ['Big Van'],
            'suv' => ['SUV'],
            'truck' => ['Truck', 'LM Cargo'],
            'bus' => ['Bus'],
            'motorcycle' => ['Motorcycle'],
        ];

        return $reverseMapping[$vehicleCategory] ?? [];
    }

    /**
     * Get all port IDs that belong to the given port group IDs
     */
    private function getPortIdsFromPortGroups(int $carrierId, array $portGroupIds): array
    {
        return CarrierPortGroupMember::query()
            ->join('carrier_port_groups', 'carrier_port_group_members.carrier_port_group_id', '=', 'carrier_port_groups.id')
            ->where('carrier_port_groups.carrier_id', $carrierId)
            ->whereIn('carrier_port_groups.id', $portGroupIds)
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
            ->pluck('carrier_port_group_members.port_id')
            ->unique()
            ->toArray();
    }
}

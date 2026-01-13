<?php

namespace App\Services\Pricing;

use App\Models\CarrierPurchaseTariff;
use App\Models\CarrierArticleMapping;
use App\Models\RobawsArticleCache;
use Carbon\Carbon;

class PurchasePriceSyncService
{
    /**
     * Sync purchase price data from a tariff to its associated article
     * This method is called from model events and will always sync the most recent active tariff
     */
    public function syncTariffToArticle(CarrierPurchaseTariff $tariff): void
    {
        $mapping = $tariff->carrierArticleMapping;
        if (!$mapping || !$mapping->article) {
            return;
        }

        $article = $mapping->article;

        // Always sync the most recent active tariff for the article
        // This ensures we don't overwrite with older data when multiple tariffs exist
        $this->syncActiveTariffForArticle($article);
    }

    /**
     * Sync the most recent active tariff for an article
     */
    public function syncActiveTariffForArticle(RobawsArticleCache $article): void
    {
        $mostRecentTariff = $this->getMostRecentActiveTariffForArticle($article);
        
        if ($mostRecentTariff) {
            $mapping = $mostRecentTariff->carrierArticleMapping;
            
            // Calculate total purchase cost (with LM filtering if applicable)
            $totalCost = $this->calculateTotalPurchaseCost($mostRecentTariff, $mapping);

            // Build detailed breakdown
            $breakdown = $this->buildPurchasePriceBreakdown($mostRecentTariff, $article);

            // Update article (use updateQuietly with explicit field list to prevent triggering model events and only update intended fields)
            $updateData = [
                'cost_price' => $totalCost,
                'purchase_price_breakdown' => $breakdown,
            ];
            if (!$article->currency && $mostRecentTariff->currency) {
                $updateData['currency'] = $mostRecentTariff->currency;
            }
            $article->updateQuietly($updateData);
        } else {
            // No active tariffs, clear purchase price data
            $this->clearPurchasePriceForArticle($article);
        }
    }

    /**
     * Clear purchase price data for an article
     */
    public function clearPurchasePriceForArticle(RobawsArticleCache $article): void
    {
        $article->updateQuietly([
            'cost_price' => null,
            'purchase_price_breakdown' => null,
        ]);
    }

    /**
     * Calculate total purchase cost from tariff
     * For LM articles, only includes fields with unit='LM'
     */
    public function calculateTotalPurchaseCost(CarrierPurchaseTariff $tariff, ?CarrierArticleMapping $mapping = null): float
    {
        // Determine category from mapping
        $category = $this->determineCategoryFromMapping($mapping);
        $isLM = ($category === 'LM');
        
        // Field to unit field mapping
        $fieldToUnitField = [
            'base_freight_amount' => 'base_freight_unit',
            'baf_amount' => 'baf_unit',
            'ets_amount' => 'ets_unit',
            'port_additional_amount' => 'port_additional_unit',
            'admin_fxe_amount' => 'admin_fxe_unit',
            'thc_amount' => 'thc_unit',
            'measurement_costs_amount' => 'measurement_costs_unit',
            'congestion_surcharge_amount' => 'congestion_surcharge_unit',
            'iccm_amount' => 'iccm_unit',
        ];
        
        $total = 0.0;
        
        foreach ($fieldToUnitField as $amountField => $unitField) {
            $value = $tariff->$amountField;
            
            // For LM category, only include fields with unit='LM'
            if ($isLM) {
                $unitValue = $tariff->$unitField ?? 'LUMPSUM';
                if ($unitValue !== 'LM') {
                    continue; // Skip this field
                }
            }
            
            if ($value !== null && $value >= 0) {
                $total += (float) $value;
            }
        }
        
        return round($total, 2);
    }

    /**
     * Build detailed purchase price breakdown structure
     */
    public function buildPurchasePriceBreakdown(CarrierPurchaseTariff $tariff, RobawsArticleCache $article): array
    {
        $mapping = $tariff->carrierArticleMapping;
        $carrier = $mapping ? $mapping->carrier : null;

        $category = $this->determineCategoryFromMapping($mapping);

        $breakdown = [
            'base_freight' => [
                'amount' => $tariff->base_freight_amount ? (float) $tariff->base_freight_amount : 0,
                'unit' => $tariff->base_freight_unit ?? 'LUMPSUM',
            ],
            'surcharges' => [],
            'total' => $this->calculateTotalPurchaseCost($tariff, $mapping), // Use filtered calculation
            'total_unit_type' => $category === 'LM' ? 'LM' : 'LUMPSUM', // Indicates if total is LM-only or includes all values
            'currency' => $tariff->currency ?? 'EUR',
            'tariff_id' => $tariff->id,
            'mapping_id' => $mapping ? $mapping->id : null,
            'carrier_id' => $carrier ? $carrier->id : null,
            'carrier_name' => $carrier ? $carrier->name : null,
            'effective_from' => $tariff->effective_from ? $tariff->effective_from->format('Y-m-d') : null,
            'effective_to' => $tariff->effective_to ? $tariff->effective_to->format('Y-m-d') : null,
            'last_synced_at' => Carbon::now()->toIso8601String(),
            'source' => $tariff->source ?? 'manual',
            'update_date' => $article->effective_update_date ? $article->effective_update_date->format('Y-m-d') : null,
            'validity_date' => $article->effective_validity_date ? $article->effective_validity_date->format('Y-m-d') : null,
        ];

        // Add surcharges
        $surchargeMap = [
            'baf_amount' => ['field' => 'baf', 'unit_field' => 'baf_unit'],
            'ets_amount' => ['field' => 'ets', 'unit_field' => 'ets_unit'],
            'port_additional_amount' => ['field' => 'port_additional', 'unit_field' => 'port_additional_unit'],
            'admin_fxe_amount' => ['field' => 'admin_fxe', 'unit_field' => 'admin_fxe_unit'],
            'thc_amount' => ['field' => 'thc', 'unit_field' => 'thc_unit'],
            'measurement_costs_amount' => ['field' => 'measurement_costs', 'unit_field' => 'measurement_costs_unit'],
            'congestion_surcharge_amount' => ['field' => 'congestion_surcharge', 'unit_field' => 'congestion_surcharge_unit'],
            'iccm_amount' => ['field' => 'iccm', 'unit_field' => 'iccm_unit'],
        ];

        foreach ($surchargeMap as $amountField => $config) {
            $amount = $tariff->$amountField;
            if ($amount !== null && $amount > 0) {
                $unitField = $config['unit_field'];
                $breakdown['surcharges'][$config['field']] = [
                    'amount' => (float) $amount,
                    'unit' => $tariff->$unitField ?? 'LUMPSUM',
                ];
            }
        }

        return $breakdown;
    }

    /**
     * Get the most recent active tariff for an article
     */
    protected function getMostRecentActiveTariffForArticle(RobawsArticleCache $article): ?CarrierPurchaseTariff
    {
        return CarrierPurchaseTariff::query()
            ->whereHas('carrierArticleMapping', function ($query) use ($article) {
                $query->where('article_id', $article->id);
            })
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now());
            })
            ->orderBy('effective_from', 'desc')
            ->orderBy('sort_order', 'asc')
            ->first();
    }

    /**
     * Check if a tariff is currently active
     */
    protected function isTariffActive(CarrierPurchaseTariff $tariff): bool
    {
        if (!$tariff->is_active) {
            return false;
        }

        $now = now();
        
        if ($tariff->effective_from && $tariff->effective_from > $now) {
            return false;
        }

        if ($tariff->effective_to && $tariff->effective_to < $now) {
            return false;
        }

        return true;
    }

    /**
     * Determine PDF category from mapping
     * Returns 'CAR'|'SVAN'|'BVAN'|'LM'|null
     */
    protected function determineCategoryFromMapping(?CarrierArticleMapping $mapping): ?string
    {
        if (!$mapping) {
            return null;
        }

        // Prefer category groups
        $categoryGroupIds = $mapping->category_group_ids ?? [];
        if (!empty($categoryGroupIds)) {
            $categoryGroups = \App\Models\CarrierCategoryGroup::whereIn('id', $categoryGroupIds)
                ->where('carrier_id', $mapping->carrier_id)
                ->get();

            foreach ($categoryGroups as $group) {
                $code = strtoupper($group->code ?? '');
                if ($code === 'CARS') {
                    return 'CAR';
                } elseif ($code === 'SMALL_VANS') {
                    return 'SVAN';
                } elseif ($code === 'BIG_VANS') {
                    return 'BVAN';
                } elseif ($code === 'LM_CARGO' || strpos($code, 'LM') !== false) {
                    return 'LM';
                }
            }
        }

        // Fallback to vehicle_categories
        $vehicleCategories = $mapping->vehicle_categories ?? [];
        if (!empty($vehicleCategories)) {
            foreach ($vehicleCategories as $category) {
                $category = strtolower($category ?? '');
                if ($category === 'car') {
                    return 'CAR';
                } elseif (in_array($category, ['small_van', 'smallvan'])) {
                    return 'SVAN';
                } elseif (in_array($category, ['big_van', 'bigvan'])) {
                    return 'BVAN';
                } elseif (in_array($category, ['truck', 'truckhead', 'trailer', 'lm', 'roro'])) {
                    return 'LM';
                }
            }
        }

        // Fallback to article code/name patterns
        if ($mapping->article) {
            $articleCode = strtoupper($mapping->article->article_code ?? '');
            $articleName = strtoupper($mapping->article->article_name ?? '');
            
            if (str_ends_with($articleCode, 'CAR') || str_contains($articleCode, 'CAR')) {
                return 'CAR';
            } elseif (str_ends_with($articleCode, 'SV') || str_contains($articleCode, 'SV')) {
                return 'SVAN';
            } elseif (str_ends_with($articleCode, 'BV') || str_contains($articleCode, 'BV')) {
                return 'BVAN';
            } elseif (str_ends_with($articleCode, 'HH') || str_contains($articleCode, 'LM') || str_contains($articleCode, 'HH')) {
                return 'LM';
            }
            
            if (str_contains($articleName, ' CAR ')) {
                return 'CAR';
            } elseif (str_contains($articleName, ' SMALL VAN')) {
                return 'SVAN';
            } elseif (str_contains($articleName, ' BIG VAN')) {
                return 'BVAN';
            } elseif (str_contains($articleName, ' LM ') || str_contains($articleName, ' LM CARGO') || str_contains($articleName, ' LM SEAFREIGHT')) {
                return 'LM';
            }
        }

        return null;
    }
}


<?php

namespace App\Services\Pricing;

use App\Models\CarrierPurchaseTariff;
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
            // Calculate total purchase cost
            $totalCost = $this->calculateTotalPurchaseCost($mostRecentTariff);

            // Build detailed breakdown
            $breakdown = $this->buildPurchasePriceBreakdown($mostRecentTariff, $article);

            // Update article (use saveQuietly to prevent triggering model events and infinite loops)
            $article->cost_price = $totalCost;
            if (!$article->currency && $mostRecentTariff->currency) {
                $article->currency = $mostRecentTariff->currency;
            }
            $article->purchase_price_breakdown = $breakdown;
            $article->saveQuietly();
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
        $article->cost_price = null;
        $article->purchase_price_breakdown = null;
        $article->saveQuietly();
    }

    /**
     * Calculate total purchase cost from tariff
     */
    public function calculateTotalPurchaseCost(CarrierPurchaseTariff $tariff): float
    {
        $total = (float) ($tariff->base_freight_amount ?? 0);
        
        // Add all surcharges
        $surchargeFields = [
            'baf_amount',
            'ets_amount',
            'port_additional_amount',
            'admin_fxe_amount',
            'thc_amount',
            'measurement_costs_amount',
            'congestion_surcharge_amount',
            'iccm_amount',
        ];

        foreach ($surchargeFields as $field) {
            if ($tariff->$field !== null) {
                $total += (float) $tariff->$field;
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

        $breakdown = [
            'base_freight' => [
                'amount' => $tariff->base_freight_amount ? (float) $tariff->base_freight_amount : 0,
                'unit' => $tariff->base_freight_unit ?? 'LUMPSUM',
            ],
            'surcharges' => [],
            'total' => $this->calculateTotalPurchaseCost($tariff),
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
}


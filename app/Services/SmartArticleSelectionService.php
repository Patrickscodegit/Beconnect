<?php

namespace App\Services;

use App\Models\QuotationRequest;
use App\Models\RobawsArticleCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\PortCodeMapper;

class SmartArticleSelectionService
{
    /**
     * Suggest parent articles based on quotation context
     * 
     * @param QuotationRequest $quotation
     * @return Collection Collection of articles with match scores
     */
    public function suggestParentArticles(QuotationRequest $quotation): Collection
    {
        // Use caching for performance
        $cacheKey = "article_suggestions_{$quotation->id}_{$quotation->updated_at->timestamp}";
        
        return Cache::remember($cacheKey, 3600, function () use ($quotation) {
            return $this->calculateSuggestions($quotation);
        });
    }

    /**
     * Calculate article suggestions without caching
     *
     * @param QuotationRequest $quotation
     * @return Collection
     */
    protected function calculateSuggestions(QuotationRequest $quotation): Collection
    {
        // Get base query using the model scope
        $articles = RobawsArticleCache::forQuotationContext($quotation)->get();

        // If no articles found with filters, fall back to all parent items
        if ($articles->isEmpty()) {
            Log::info('No articles matched quotation context, returning all parent items', [
                'quotation_id' => $quotation->id
            ]);
            
            $articles = RobawsArticleCache::active()
                ->parentItems()
                ->validAsOf(now())
                ->get();
        }

        // Calculate match score for each article
        return $articles->map(function ($article) use ($quotation) {
            $score = $this->calculateMatchScore($article, $quotation);
            $reasons = $this->getMatchReasons($article, $quotation);

            return [
                'article' => $article,
                'match_score' => $score,
                'match_percentage' => min(100, $score), // Cap at 100%
                'match_reasons' => $reasons,
                'confidence' => $this->getConfidenceLevel($score),
            ];
        })->sortByDesc('match_score')->values();
    }

    /**
     * Calculate match score for an article
     *
     * @param RobawsArticleCache $article
     * @param QuotationRequest $quotation
     * @return int Score from 0-200
     */
    protected function calculateMatchScore(RobawsArticleCache $article, QuotationRequest $quotation): int
    {
        $score = 0;
        $debugBreakdown = [];

        // Base score for being a parent item
        if ($article->is_parent_item) {
            $score += 10;
            $debugBreakdown['parent_item'] = 10;
        }

        // Extract port codes (returns 3-letter Robaws codes: ANR, CKY, etc.)
        $polCode = $this->extractPortCode($quotation->pol);
        $podCode = $this->extractPortCode($quotation->pod);
        
        // Normalize article port codes from "City, Country (ANR)" format to "ANR"
        $articlePolCode = PortCodeMapper::normalizePortCode($article->pol_code);
        $articlePodCode = PortCodeMapper::normalizePortCode($article->pod_name);

        // POL + POD exact match: 100 points
        if ($polCode && $podCode && 
            $articlePolCode === $polCode && 
            $articlePodCode === $podCode) {
            $score += 100;
            $debugBreakdown['route_exact_match'] = 100;
        } else {
            // Partial match: POL only (40 points) or POD only (40 points)
            if ($polCode && $articlePolCode === $polCode) {
                $score += 40;
                $debugBreakdown['pol_match'] = 40;
            }
            if ($podCode && $articlePodCode === $podCode) {
                $score += 40;
                $debugBreakdown['pod_match'] = 40;
            }
        }

        // Shipping line match: 50 points
        $carrierMatched = false;
        if ($quotation->selected_schedule_id && $quotation->selectedSchedule) {
            $schedule = $quotation->selectedSchedule;
            if ($schedule->carrier && $article->shipping_line) {
                if (stripos($article->shipping_line, $schedule->carrier->name) !== false) {
                    $score += 50;
                    $carrierMatched = true;
                    $debugBreakdown['carrier_via_schedule'] = 50;
                }
            }
        }

        // Service type match: 30 points
        if ($quotation->service_type && $article->service_type) {
            if (strtoupper($quotation->service_type) === strtoupper($article->service_type)) {
                $score += 30;
                $debugBreakdown['service_type'] = 30;
            }
        }

        // Commodity type match: 20 points per matching commodity
        if ($quotation->commodityItems && $quotation->commodityItems->count() > 0) {
            $quotationCommodities = $this->extractCommodityTypes($quotation);
            if ($article->commodity_type && in_array($article->commodity_type, $quotationCommodities)) {
                $score += 20;
                $debugBreakdown['commodity'] = 20;
            }
        }

        // Validity bonus: 5 points if validity date is far in future (>30 days)
        if ($article->validity_date && $article->validity_date->isFuture()) {
            $daysValid = now()->diffInDays($article->validity_date);
            if ($daysValid > 30) {
                $score += 5;
                $debugBreakdown['validity'] = 5;
            }
        }

        // DEBUG LOGGING
        Log::info('Smart Match Score Calculation', [
            'quotation_id' => $quotation->id,
            'article_id' => $article->id,
            'article_description' => $article->description,
            'total_score' => $score,
            'breakdown' => $debugBreakdown,
            'context' => [
                'quotation_pol' => $quotation->pol,
                'quotation_pod' => $quotation->pod,
                'quotation_service_type' => $quotation->service_type,
                'quotation_preferred_carrier' => $quotation->preferred_carrier,
                'quotation_selected_schedule_id' => $quotation->selected_schedule_id,
                'article_pol_code_raw' => $article->pol_code,
                'article_pol_code_normalized' => $articlePolCode,
                'article_pod_name_raw' => $article->pod_name,
                'article_pod_code_normalized' => $articlePodCode,
                'article_shipping_line' => $article->shipping_line,
                'article_applicable_carriers' => $article->applicable_carriers,
                'article_service_type' => $article->service_type,
                'article_commodity_type' => $article->commodity_type,
                'extracted_pol_code' => $polCode,
                'extracted_pod_code' => $podCode,
                'carrier_matched' => $carrierMatched,
            ]
        ]);

        return $score;
    }

    /**
     * Get reasons why an article matches
     *
     * @param RobawsArticleCache $article
     * @param QuotationRequest $quotation
     * @return array Array of match reason strings
     */
    protected function getMatchReasons(RobawsArticleCache $article, QuotationRequest $quotation): array
    {
        $reasons = [];

        // Extract port codes (returns 3-letter Robaws codes)
        $polCode = $this->extractPortCode($quotation->pol);
        $podCode = $this->extractPortCode($quotation->pod);
        
        // Normalize article port codes from "City, Country (ANR)" format
        $articlePolCode = PortCodeMapper::normalizePortCode($article->pol_code);
        $articlePodCode = PortCodeMapper::normalizePortCode($article->pod_name);

        // Check POL/POD matches
        if ($polCode && $podCode && 
            $articlePolCode === $polCode && 
            $articlePodCode === $podCode) {
            $reasons[] = "Exact route match: {$polCode} → {$podCode}";
        } else {
            if ($polCode && $articlePolCode === $polCode) {
                $reasons[] = "POL matches: {$polCode}";
            }
            if ($podCode && $articlePodCode === $podCode) {
                $reasons[] = "POD matches: {$podCode}";
            }
        }

        // Check shipping line match
        if ($quotation->selected_schedule_id && $quotation->selectedSchedule) {
            $schedule = $quotation->selectedSchedule;
            if ($schedule->carrier && $article->shipping_line) {
                if (stripos($article->shipping_line, $schedule->carrier->name) !== false) {
                    $reasons[] = "Carrier: {$article->shipping_line}";
                }
            }
        }

        // Check service type match
        if ($quotation->service_type && $article->service_type) {
            if (strtoupper($quotation->service_type) === strtoupper($article->service_type)) {
                $reasons[] = "Service: {$article->service_type}";
            }
        }

        // Check commodity type match
        if ($quotation->commodityItems && $quotation->commodityItems->count() > 0) {
            $quotationCommodities = $this->extractCommodityTypes($quotation);
            if ($article->commodity_type && in_array($article->commodity_type, $quotationCommodities)) {
                $reasons[] = "Commodity: {$article->commodity_type}";
            }
        }

        // Add parent item indicator
        if ($article->is_parent_item) {
            $reasons[] = "Parent article";
        }

        return $reasons;
    }

    /**
     * Get confidence level based on score
     *
     * @param int $score
     * @return string Confidence level: high, medium, low
     */
    protected function getConfidenceLevel(int $score): string
    {
        if ($score >= 100) {
            return 'high';
        } elseif ($score >= 50) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Extract port code from string
     *
     * @param string|null $portString
     * @return string|null
     */
    protected function extractPortCode(?string $portString): ?string
    {
        if (empty($portString)) {
            return null;
        }

        // Use the PortCodeMapper service to handle various formats
        return PortCodeMapper::getPortCode($portString);
    }

    /**
     * Extract commodity types from quotation
     *
     * @param QuotationRequest $quotation
     * @return array Array of commodity type strings
     */
    protected function extractCommodityTypes(QuotationRequest $quotation): array
    {
        if (!$quotation->commodityItems || $quotation->commodityItems->count() === 0) {
            return [];
        }

        return $quotation->commodityItems->map(function ($item) {
            return $this->normalizeCommodityType($item);
        })->filter()->unique()->values()->toArray();
    }

    /**
     * Normalize commodity type from commodity item
     *
     * @param mixed $commodityItem
     * @return string|null
     */
    protected function normalizeCommodityType($commodityItem): ?string
    {
        if (!$commodityItem) {
            return null;
        }

        $type = $commodityItem->commodity_type ?? null;

        // Map internal commodity types to Robaws article types
        $typeMapping = [
            'vehicles' => $this->getVehicleCategoryMapping($commodityItem),
            'machinery' => 'Machinery',
            'boat' => 'Boat',
            'general_cargo' => 'General Cargo',
        ];

        return $typeMapping[$type] ?? null;
    }

    /**
     * Get specific vehicle category for mapping
     *
     * @param mixed $commodityItem
     * @return string|null
     */
    protected function getVehicleCategoryMapping($commodityItem): ?string
    {
        $category = $commodityItem->vehicle_category ?? null;

        // Map vehicle categories to Robaws types
        $vehicleMapping = [
            'car' => 'Car',
            'suv' => 'SUV',
            'small_van' => 'Small Van',
            'big_van' => 'Big Van',
            'truck' => 'Truck',
            'truckhead' => 'Truckhead',
            'bus' => 'Bus',
            'motorcycle' => 'Motorcycle',
        ];

        return $vehicleMapping[$category] ?? 'Car'; // Default to Car
    }

    /**
     * Clear cached suggestions for a quotation
     *
     * @param QuotationRequest $quotation
     * @return void
     */
    public function clearCache(QuotationRequest $quotation): void
    {
        $cacheKey = "article_suggestions_{$quotation->id}_{$quotation->updated_at->timestamp}";
        Cache::forget($cacheKey);
    }

    /**
     * Clear all article suggestion caches
     *
     * @return void
     */
    public function clearAllCaches(): void
    {
        Cache::flush();
        Log::info('Cleared all article suggestion caches');
    }

    /**
     * Get top N suggestions above a minimum score threshold
     *
     * @param QuotationRequest $quotation
     * @param int $limit Number of suggestions to return
     * @param int $minScore Minimum score threshold
     * @return Collection
     */
    public function getTopSuggestions(QuotationRequest $quotation, int $limit = 5, int $minScore = 50): Collection
    {
        $suggestions = $this->suggestParentArticles($quotation);
        
        return $suggestions->filter(function ($suggestion) use ($minScore) {
            return $suggestion['match_score'] >= $minScore;
        })->take($limit);
    }
}


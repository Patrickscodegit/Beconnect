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
        // Get base query using the model scope (now requires POL/POD match)
        $articles = RobawsArticleCache::forQuotationContext($quotation)->get();

        // NO FALLBACK - only return articles that match POL/POD exactly
        // If no articles match, return empty collection (100% match required)
        if ($articles->isEmpty()) {
            Log::info('No articles matched quotation POL/POD exactly, returning empty results', [
                'quotation_id' => $quotation->id,
                'pol' => $quotation->pol,
                'pod' => $quotation->pod
            ]);
            
            return collect([]);
        }

        // Calculate match score for each article (should all be 100% matches now)
        return $articles->map(function ($article) use ($quotation) {
            $score = $this->calculateMatchScore($article, $quotation);
            $reasons = $this->getMatchReasons($article, $quotation);

            return [
                'article' => $article,
                'match_score' => $score,
                'match_percentage' => 100, // Always 100% since we filter for exact matches
                'match_reasons' => $reasons,
                'confidence' => 'high', // Always high since matches are required
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

        // Direct comparison of POL/POD (both store full format: "Antwerp (ANR), Belgium")
        $quotationPol = $quotation->pol;
        $quotationPod = $quotation->pod;
        $articlePol = $article->pol;
        $articlePod = $article->pod;

        // POL + POD exact match: 100 points
        if ($quotationPol && $quotationPod && $articlePol && $articlePod &&
            $articlePol === $quotationPol && 
            $articlePod === $quotationPod) {
            $score += 100;
            $debugBreakdown['route_exact_match'] = 100;
        } else {
            // Flexible matching: exact match OR quotation string is contained in article
            // This handles cases where quotation has "Antwerp" and article has "Antwerp, Belgium (ANR)"
            if ($quotationPol && $articlePol) {
                if ($articlePol === $quotationPol || 
                    stripos($articlePol, $quotationPol) !== false) {
                    $score += 40;
                    $debugBreakdown['pol_match'] = 40;
                }
            }
            if ($quotationPod && $articlePod) {
                if ($articlePod === $quotationPod || 
                    stripos($articlePod, $quotationPod) !== false) {
                    $score += 40;
                    $debugBreakdown['pod_match'] = 40;
                }
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

        // Transport mode match: 30 points
        $quotationMode = $this->mapServiceTypeToTransportMode($quotation->service_type);
        if ($quotationMode && $article->transport_mode) {
            if (strtoupper($quotationMode) === strtoupper($article->transport_mode)) {
                $score += 30;
                $debugBreakdown['transport_mode'] = 30;
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
                'quotation_transport_mode' => $quotationMode,
                'quotation_preferred_carrier' => $quotation->preferred_carrier,
                'quotation_selected_schedule_id' => $quotation->selected_schedule_id,
                'article_pol' => $article->pol,
                'article_pod' => $article->pod,
                'article_shipping_line' => $article->shipping_line,
                'article_transport_mode' => $article->transport_mode,
                'article_commodity_type' => $article->commodity_type,
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

        // Direct POL/POD comparison (both use full format)
        $quotationPol = $quotation->pol;
        $quotationPod = $quotation->pod;
        $articlePol = $article->pol;
        $articlePod = $article->pod;

        // Check POL/POD matches
        if ($quotationPol && $quotationPod && $articlePol && $articlePod &&
            $articlePol === $quotationPol && 
            $articlePod === $quotationPod) {
            $reasons[] = "Exact route: {$quotationPol} → {$quotationPod}";
        } else {
            if ($quotationPol && $articlePol) {
                if ($articlePol === $quotationPol || stripos($articlePol, $quotationPol) !== false) {
                    $reasons[] = "POL: {$articlePol}";
                }
            }
            if ($quotationPod && $articlePod) {
                if ($articlePod === $quotationPod || stripos($articlePod, $quotationPod) !== false) {
                    $reasons[] = "POD: {$articlePod}";
                }
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

        // Check transport mode match
        $quotationMode = $this->mapServiceTypeToTransportMode($quotation->service_type);
        if ($quotationMode && $article->transport_mode) {
            if (strtoupper($quotationMode) === strtoupper($article->transport_mode)) {
                $reasons[] = "Transport mode: {$article->transport_mode}";
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

    // extractPortCode() method removed - no longer needed with direct string comparison

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

        return $quotation->commodityItems
            ->flatMap(function ($item) {
                return $this->normalizeCommodityTypes($item);
            })
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Normalize commodity types from commodity item
     *
     * @param mixed $commodityItem
     * @return array<string>
     */
    protected function normalizeCommodityTypes($commodityItem): array
    {
        if (!$commodityItem) {
            return [];
        }

        $type = $commodityItem->commodity_type ?? null;

        // Map internal commodity types to Robaws article types
        $typeMapping = [
            'vehicles' => $this->getVehicleCategoryMappings($commodityItem),
            'machinery' => ['Machinery'],
            'boat' => ['Boat'],
            'general_cargo' => ['General Cargo'],
        ];

        return $typeMapping[$type] ?? [];
    }

    /**
     * Get specific vehicle category mappings
     *
     * @param mixed $commodityItem
     * @return array<string>
     */
    protected function getVehicleCategoryMappings($commodityItem): array
    {
        $category = $commodityItem->category ?? $commodityItem->vehicle_category ?? null;

        // Map vehicle categories to Robaws types
        $vehicleMapping = [
            'car' => ['CAR'],
            'suv' => ['SUV'],
            'small_van' => ['SMALL VAN'],
            'big_van' => ['BIG VAN', 'LM CARGO'],
            'truck' => ['TRUCK', 'HH', 'LM CARGO'],
            'truckhead' => ['TRUCKHEAD', 'HH', 'LM CARGO'],
            'bus' => ['BUS', 'HH', 'LM CARGO'],
            'motorcycle' => ['MOTORCYCLE'],
        ];

        return $vehicleMapping[$category] ?? ['CAR'];
    }

    /**
     * Map quotation-level service type to canonical transport mode
     */
    protected function mapServiceTypeToTransportMode(?string $serviceType): ?string
    {
        if (!$serviceType) {
            return null;
        }

        $upper = strtoupper($serviceType);

        if (str_contains($upper, 'RORO')) {
            return 'RORO';
        }

        if (str_contains($upper, 'FCL') && str_contains($upper, 'CONSOL')) {
            return 'FCL CONSOL';
        }

        if (str_contains($upper, 'FCL')) {
            return 'FCL';
        }

        if (str_contains($upper, 'LCL')) {
            return 'LCL';
        }

        if (str_contains($upper, 'AIR')) {
            return 'AIRFREIGHT';
        }

        if (str_contains($upper, 'BB')) {
            return 'BB';
        }

        if (str_contains($upper, 'ROAD')) {
            return 'ROAD TRANSPORT';
        }

        if (str_contains($upper, 'CUSTOMS')) {
            return 'CUSTOMS';
        }

        return null;
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
     * Note: Since we now filter for exact matches, all results are 100% matches
     *
     * @param QuotationRequest $quotation
     * @param int $limit Number of suggestions to return
     * @param int $minScore Minimum score threshold (ignored now - all matches are 100%)
     * @return Collection
     */
    public function getTopSuggestions(QuotationRequest $quotation, int $limit = 5, int $minScore = 50): Collection
    {
        $suggestions = $this->suggestParentArticles($quotation);
        
        $exactMatches = $suggestions->filter(function ($suggestion) use ($quotation) {
            /** @var \App\Models\RobawsArticleCache $article */
            $article = $suggestion['article'];
            return $article->pol === $quotation->pol && $article->pod === $quotation->pod;
        });

        if ($exactMatches->isNotEmpty()) {
            return $exactMatches->take($limit);
        }

        // Fallback: return best matches but ensure a decent score threshold
        return $suggestions->filter(fn ($suggestion) => ($suggestion['match_score'] ?? 0) >= 80)
            ->take($limit);
    }
}


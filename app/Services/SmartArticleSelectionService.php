<?php

namespace App\Services;

use App\Models\QuotationRequest;
use App\Models\RobawsArticleCache;
use App\Models\QuotationCommodityItem;
use App\Models\CarrierCategoryGroupMember;
use App\Models\CarrierCategoryGroup;
use App\Services\CarrierRules\DTOs\CargoInputDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\PortCodeMapper;
use App\Services\CarrierRules\CarrierRuleResolver;

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
        // Include commodity items hash in cache key to ensure cache invalidates when commodity changes
        // This is in addition to updated_at timestamp for extra safety
        $commodityHash = '';
        if ($quotation->commodityItems && $quotation->commodityItems->count() > 0) {
            $commodityHash = $quotation->commodityItems
                ->map(fn($item) => ($item->commodity_type ?? '') . '|' . ($item->id ?? ''))
                ->sort()
                ->implode('|');
        }
        $commodityHash = md5($commodityHash); // Hash for shorter cache key
        
        // Use caching for performance
        $cacheKey = "article_suggestions_{$quotation->id}_{$quotation->updated_at->timestamp}_{$commodityHash}";
        
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
        $shouldDebugLog = $this->shouldWriteDebugLog();
        
        if ($shouldDebugLog) {
            Log::debug('SmartArticleSelectionService: calculateSuggestions entry', [
                'quotation_id' => $quotation->id ?? null,
                'request_number' => $quotation->request_number ?? null,
            ]);
        }
        
        // Get base query using the model scope (now requires POL/POD match)
        $articles = RobawsArticleCache::forQuotationContext($quotation)->get();

        // Enforce strict alignment via carrier category group mappings
        $strictMappedIds = $this->getStrictMappedArticleIds($quotation);
        if (empty($strictMappedIds)) {
            if ($shouldDebugLog) {
                Log::debug('SmartArticleSelectionService: No strict mappings found', [
                    'quotation_id' => $quotation->id ?? null,
                ]);
            }

            return collect([]);
        }

        $articles = $articles->filter(function ($article) use ($strictMappedIds) {
            return in_array($article->id, $strictMappedIds, true);
        })->values();

        if ($shouldDebugLog) {
            Log::debug('SmartArticleSelectionService: Articles after strict mapping filter', [
                'quotation_id' => $quotation->id ?? null,
                'articles_count' => $articles->count(),
                'article_ids' => $articles->pluck('id')->toArray(),
            ]);
        }

        // NO FALLBACK - only return articles that match POL/POD exactly
        // If no articles match, return empty collection (100% match required)
        if ($articles->isEmpty()) {
            if ($shouldDebugLog) {
                Log::debug('SmartArticleSelectionService: No articles found - empty result', [
                    'quotation_id' => $quotation->id ?? null,
                    'pol' => $quotation->pol ?? null,
                    'pod' => $quotation->pod ?? null,
                ]);
            }
            
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
        return QuotationCommodityItem::normalizeCommodityTypes($commodityItem);
    }

    /**
     * Strict eligibility check for manual/auto-added articles.
     */
    public function isStrictlyEligible(QuotationRequest $quotation, RobawsArticleCache $article): bool
    {
        $strictMappedIds = $this->getStrictMappedArticleIds($quotation);
        if (empty($strictMappedIds) || !in_array($article->id, $strictMappedIds, true)) {
            return false;
        }

        if (!$this->matchesRoute($quotation, $article)) {
            return false;
        }

        $schedule = $quotation->selectedSchedule;
        $carrierId = $schedule?->carrier_id ?? $schedule?->carrier?->id;
        if ($carrierId && $article->shipping_carrier_id && (int) $article->shipping_carrier_id !== (int) $carrierId) {
            return false;
        }

        return true;
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
            'trailer' => ['TRAILER', 'HH', 'LM CARGO'],
            'bus' => ['BUS', 'HH', 'LM CARGO'],
            'motorcycle' => ['MOTORCYCLE'],
        ];

        // Return empty array if category not found (don't default to CAR)
        return $vehicleMapping[$category] ?? [];
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

    private function getStrictMappedArticleIds(QuotationRequest $quotation): array
    {
        $quotation->loadMissing(['commodityItems', 'selectedSchedule.carrier', 'selectedSchedule.podPort']);

        $schedule = $quotation->selectedSchedule;
        $carrierId = $schedule?->carrier_id ?? $schedule?->carrier?->id;
        if (!$carrierId) {
            return [];
        }

        $podPortId = $schedule?->pod_id ?? $quotation->pod_port_id;
        if (!$podPortId) {
            return [];
        }

        $vehicleCategories = $quotation->commodityItems
            ->pluck('category')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($vehicleCategories)) {
            return [];
        }

        $acceptanceGroupCodes = $quotation->commodityItems
            ->map(function ($item) {
                $meta = $item->carrier_rule_meta;
                if (!is_array($meta)) {
                    return null;
                }
                return $meta['matched_category_group'] ?? null;
            })
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $dimensionAlignedCodes = $this->getDimensionAlignedCategoryGroupCodes(
            $quotation,
            $carrierId,
            $podPortId
        );
        if (!empty($dimensionAlignedCodes)) {
            Log::info('SmartArticleSelectionService: Using dimension-aligned category groups', [
                'quotation_id' => $quotation->id ?? null,
                'carrier_id' => $carrierId,
                'matched_category_groups' => $acceptanceGroupCodes,
                'dimension_aligned_groups' => $dimensionAlignedCodes,
            ]);
            $acceptanceGroupCodes = $dimensionAlignedCodes;
        }

        $categoryGroupIds = [];
        if (!empty($acceptanceGroupCodes)) {
            $categoryGroupIds = CarrierCategoryGroup::where('carrier_id', $carrierId)
                ->whereIn('code', $acceptanceGroupCodes)
                ->pluck('id')
                ->unique()
                ->filter()
                ->values()
                ->toArray();
        }

        if (!empty($categoryGroupIds)) {
            Log::info('SmartArticleSelectionService: Using acceptance category groups', [
                'quotation_id' => $quotation->id ?? null,
                'carrier_id' => $carrierId,
                'matched_category_groups' => $acceptanceGroupCodes,
                'category_group_ids' => $categoryGroupIds,
            ]);
        } else {
            $members = CarrierCategoryGroupMember::whereHas('categoryGroup', function ($q) use ($carrierId) {
                $q->where('carrier_id', $carrierId)->where('is_active', true);
            })
                ->whereIn('vehicle_category', $vehicleCategories)
                ->where('is_active', true)
                ->get();

            $categoryGroupIds = $members->pluck('carrier_category_group_id')->unique()->filter()->values()->toArray();
        }
        if (empty($categoryGroupIds)) {
            return [];
        }

        $resolver = app(CarrierRuleResolver::class);
        $portGroupIds = $resolver->resolvePortGroupIdsForPort($carrierId, $podPortId);
        $allMappings = collect();

        // Query mappings directly to ensure correct filtering
        foreach ($categoryGroupIds as $categoryGroupId) {
            $categoryGroupVariants = [(string) $categoryGroupId, (int) $categoryGroupId];
            $portGroupVariants = array_values(array_unique(array_merge(
                $portGroupIds,
                array_map('strval', $portGroupIds)
            )));

            $query = \App\Models\CarrierArticleMapping::query()
                ->where('carrier_id', $carrierId)
                ->active();

            // Category group filtering
            $query->where(function ($q) use ($categoryGroupVariants) {
                $q->whereNull('category_group_ids');
                foreach ($categoryGroupVariants as $variant) {
                    $q->orWhereJsonContains('category_group_ids', $variant);
                }
            });

            // Port filtering
            $query->where(function ($q) use ($podPortId, $portGroupVariants) {
                // Global rules (no port scope)
                $q->where(function ($q2) {
                    $q2->whereNull('port_ids')
                       ->whereNull('port_group_ids');
                });

                if ($podPortId !== null) {
                    // Port-specific rules
                    $q->orWhereJsonContains('port_ids', (string) $podPortId)
                      ->orWhereJsonContains('port_ids', (int) $podPortId);

                    // Port group rules (only when no specific port_ids are set)
                    if (!empty($portGroupVariants)) {
                        foreach ($portGroupVariants as $groupId) {
                            $q->orWhere(function ($qq) use ($groupId) {
                                $qq->whereNull('port_ids')
                                    ->whereJsonContains('port_group_ids', $groupId);
                            });
                        }
                    }
                }
            });

            $mappings = $query->get();
            $allMappings = $allMappings->merge($mappings);
        }

        $mappedIds = $allMappings->pluck('article_id')->unique()->values()->all();

        if (empty($mappedIds) && in_array('HH', $acceptanceGroupCodes, true)) {
            $fallbackGroupIds = CarrierCategoryGroup::where('carrier_id', $carrierId)
                ->whereIn('code', ['LM_CARGO_TRUCKS', 'LM_CARGO_TRAILERS'])
                ->pluck('id')
                ->unique()
                ->filter()
                ->values()
                ->toArray();

            if (!empty($fallbackGroupIds)) {
                Log::info('SmartArticleSelectionService: Falling back to LM cargo groups for HH', [
                    'quotation_id' => $quotation->id ?? null,
                    'carrier_id' => $carrierId,
                    'fallback_group_ids' => $fallbackGroupIds,
                ]);

                foreach ($fallbackGroupIds as $categoryGroupId) {
                    $categoryGroupVariants = [(string) $categoryGroupId, (int) $categoryGroupId];
                    $portGroupVariants = array_values(array_unique(array_merge(
                        $portGroupIds,
                        array_map('strval', $portGroupIds)
                    )));

                    $query = \App\Models\CarrierArticleMapping::query()
                        ->where('carrier_id', $carrierId)
                        ->active();

                    $query->where(function ($q) use ($categoryGroupVariants) {
                        $q->whereNull('category_group_ids');
                        foreach ($categoryGroupVariants as $variant) {
                            $q->orWhereJsonContains('category_group_ids', $variant);
                        }
                    });

                    $query->where(function ($q) use ($podPortId, $portGroupVariants) {
                        $q->where(function ($q2) {
                            $q2->whereNull('port_ids')
                               ->whereNull('port_group_ids');
                        });

                        if ($podPortId !== null) {
                            $q->orWhereJsonContains('port_ids', (string) $podPortId)
                              ->orWhereJsonContains('port_ids', (int) $podPortId);

                            if (!empty($portGroupVariants)) {
                                foreach ($portGroupVariants as $groupId) {
                                    $q->orWhere(function ($qq) use ($groupId) {
                                        $qq->whereNull('port_ids')
                                            ->whereJsonContains('port_group_ids', $groupId);
                                    });
                                }
                            }
                        }
                    });

                    $mappings = $query->get();
                    $allMappings = $allMappings->merge($mappings);
                }

                $mappedIds = $allMappings->pluck('article_id')->unique()->values()->all();
            }
        }

        return $mappedIds;
    }

    private function getDimensionAlignedCategoryGroupCodes(
        QuotationRequest $quotation,
        int $carrierId,
        int $podPortId
    ): array {
        $schedule = $quotation->selectedSchedule;
        if (!$schedule) {
            return [];
        }

        $resolver = app(CarrierRuleResolver::class);
        $alignedCodes = [];

        foreach ($quotation->commodityItems as $item) {
            $meta = $item->carrier_rule_meta;
            if (!is_array($meta) || empty($meta['violations'])) {
                continue;
            }

            $hasMaxViolation = collect($meta['violations'])
                ->contains(fn($violation) => str_starts_with((string) $violation, 'max_'));
            if (!$hasMaxViolation) {
                continue;
            }

            $input = CargoInputDTO::fromCommodityItem($item, $schedule->podPort, $schedule);
            $candidateCategories = $this->getDimensionOverrideCategories($item->category);

            foreach ($candidateCategories as $category) {
                $rule = $resolver->resolveAcceptanceRule(
                    $carrierId,
                    $podPortId,
                    $category,
                    null,
                    $schedule->vessel_name,
                    $schedule->vessel_class
                );

                if (!$rule || !$this->fitsWithinMaxDimensions($input, $rule)) {
                    continue;
                }

                $code = $this->resolveCategoryGroupCodeForCategory($carrierId, $category);
                if ($code) {
                    $alignedCodes[] = $code;
                }
                break;
            }
        }

        return array_values(array_unique(array_filter($alignedCodes)));
    }

    private function getDimensionOverrideCategories(?string $vehicleCategory): array
    {
        $category = $vehicleCategory ?? '';
        $paths = [
            'car' => ['car', 'small_van', 'big_van', 'truck', 'high_and_heavy'],
            'small_van' => ['small_van', 'big_van', 'truck', 'high_and_heavy'],
            'big_van' => ['big_van', 'truck', 'high_and_heavy'],
            'suv' => ['suv', 'big_van', 'truck', 'high_and_heavy'],
        ];

        if (isset($paths[$category])) {
            return $paths[$category];
        }

        if ($category !== '') {
            return [$category, 'high_and_heavy'];
        }

        return ['high_and_heavy'];
    }

    private function fitsWithinMaxDimensions(CargoInputDTO $input, $rule): bool
    {
        if ($rule->max_length_cm && $input->lengthCm > 0 && $input->lengthCm > $rule->max_length_cm) {
            return false;
        }

        if ($rule->max_width_cm && $input->widthCm > 0 && $input->widthCm > $rule->max_width_cm) {
            return false;
        }

        if ($rule->max_height_cm && $input->heightCm > 0 && $input->heightCm > $rule->max_height_cm) {
            return false;
        }

        if ($rule->max_weight_kg && $input->weightKg > 0 && $input->weightKg > $rule->max_weight_kg) {
            return false;
        }

        if ($rule->max_cbm && $input->cbm > 0 && $input->cbm > $rule->max_cbm) {
            return false;
        }

        return true;
    }

    private function resolveCategoryGroupCodeForCategory(int $carrierId, string $vehicleCategory): ?string
    {
        $member = CarrierCategoryGroupMember::whereHas('categoryGroup', function ($q) use ($carrierId) {
            $q->where('carrier_id', $carrierId)->where('is_active', true);
        })
            ->where('vehicle_category', $vehicleCategory)
            ->where('is_active', true)
            ->with('categoryGroup')
            ->first();

        return $member?->categoryGroup?->code;
    }

    private function filterMappingsByPort($mappings, ?int $portId, array $portGroupIds = []): Collection
    {
        if ($portId === null) {
            return $mappings;
        }

        return $mappings->filter(function ($mapping) use ($portId, $portGroupIds) {
            $mappingPortIds = $mapping->port_ids ?? [];
            $mappingPortGroupIds = $mapping->port_group_ids ?? [];

            // Global mappings (no port scope)
            if (empty($mappingPortIds) && empty($mappingPortGroupIds)) {
                return true;
            }

            // Match via port_ids (if set)
            if (!empty($mappingPortIds) && in_array($portId, $mappingPortIds, false)) {
                return true;
            }

            // Match via port_group_ids only when no specific port_ids are set
            if (empty($mappingPortIds) && !empty($mappingPortGroupIds) && !empty($portGroupIds)) {
                foreach ($portGroupIds as $groupId) {
                    if (in_array($groupId, $mappingPortGroupIds, false)) {
                        return true;
                    }
                }
            }

            return false;
        });
    }

    private function matchesRoute(QuotationRequest $quotation, RobawsArticleCache $article): bool
    {
        if ($quotation->pol_port_id && $quotation->pod_port_id && $article->pol_port_id && $article->pod_port_id) {
            return (int) $quotation->pol_port_id === (int) $article->pol_port_id
                && (int) $quotation->pod_port_id === (int) $article->pod_port_id;
        }

        if ($quotation->pol && $quotation->pod && $article->pol && $article->pod) {
            return trim($quotation->pol) === trim($article->pol)
                && trim($quotation->pod) === trim($article->pod);
        }

        return false;
    }

    protected function shouldWriteDebugLog(): bool
    {
        return (bool) env('SMART_ARTICLE_DEBUG_LOG', false);
    }

    /**
     * Clear cached suggestions for a quotation
     * Clears cache with current updated_at timestamp and commodity hash
     *
     * @param QuotationRequest $quotation
     * @return void
     */
    public function clearCache(QuotationRequest $quotation): void
    {
        // Calculate commodity hash (same logic as in suggestParentArticles)
        $commodityHash = '';
        if ($quotation->commodityItems && $quotation->commodityItems->count() > 0) {
            $commodityHash = $quotation->commodityItems
                ->map(fn($item) => ($item->commodity_type ?? '') . '|' . ($item->id ?? ''))
                ->sort()
                ->implode('|');
        }
        $commodityHash = md5($commodityHash);
        
        // Clear cache with current format (includes commodity hash)
        $cacheKey = "article_suggestions_{$quotation->id}_{$quotation->updated_at->timestamp}_{$commodityHash}";
        Cache::forget($cacheKey);
        
        // Also clear old format (without commodity hash) for backward compatibility
        $oldKey = "article_suggestions_{$quotation->id}_{$quotation->updated_at->timestamp}";
        Cache::forget($oldKey);
        
        Log::debug('Cleared article suggestion cache', [
            'quotation_id' => $quotation->id,
            'updated_at' => $quotation->updated_at->timestamp,
            'commodity_hash' => $commodityHash
        ]);
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
        $shouldDebugLog = $this->shouldWriteDebugLog();

        if ($shouldDebugLog) {
            Log::debug('SmartArticleSelectionService: getTopSuggestions entry', [
                'quotation_id' => $quotation->id ?? null,
                'limit' => $limit,
                'min_score' => $minScore,
            ]);
        }
        
        $suggestions = $this->suggestParentArticles($quotation);
        
        if ($shouldDebugLog) {
            Log::debug('SmartArticleSelectionService: After suggestParentArticles', [
                'quotation_id' => $quotation->id ?? null,
                'suggestions_count' => $suggestions->count(),
            ]);
        }

        $exactMatches = $suggestions->filter(function ($suggestion) use ($quotation) {
            /** @var \App\Models\RobawsArticleCache $article */
            $article = $suggestion['article'];
            return $article->pol === $quotation->pol && $article->pod === $quotation->pod;
        });

        if ($shouldDebugLog) {
            Log::debug('SmartArticleSelectionService: Exact matches filter', [
                'quotation_id' => $quotation->id ?? null,
                'quotation_pol' => $quotation->pol ?? null,
                'quotation_pod' => $quotation->pod ?? null,
                'exact_matches_count' => $exactMatches->count(),
            ]);
        }

        if ($exactMatches->isNotEmpty()) {
            return $exactMatches->take($limit);
        }

        // Fallback: return best matches but ensure a decent score threshold
        $filtered = $suggestions->filter(fn ($suggestion) => ($suggestion['match_score'] ?? 0) >= 80)
            ->take($limit);
            
        if ($shouldDebugLog) {
            Log::debug('SmartArticleSelectionService: getTopSuggestions exit (fallback)', [
                'quotation_id' => $quotation->id ?? null,
                'filtered_count' => $filtered->count(),
            ]);
        }
        
        return $filtered;
    }

    /**
     * Public helper to retrieve strict mapped article IDs for a quotation.
     */
    public function getStrictMappedArticleIdsForQuotation(QuotationRequest $quotation): array
    {
        return $this->getStrictMappedArticleIds($quotation);
    }
}


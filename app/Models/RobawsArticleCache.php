<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RobawsArticleCache extends Model
{
    protected $table = 'robaws_articles_cache';
    
    protected $fillable = [
        'robaws_article_id',
        'article_code',
        'article_name',
        'description',
        'category',
        'applicable_routes',
        'applicable_services',
        'min_quantity',
        'max_quantity',
        'tier_label',
        'unit_price',
        'currency',
        'unit_type',
        'pricing_formula',
        'profit_margins',
        'is_parent_article',
        'is_surcharge',
        'is_hinterland_waiver',
        'is_active',
        'requires_manual_review',
        'last_synced_at',
        'last_modified_at',
        // Article metadata from Robaws ARTICLE INFO
        'shipping_line',
        'service_type',
        'transport_mode',
        'pol_terminal',
        'is_parent_item',
        'article_type',
        'cost_side',
        'pol_code',
        'pod_code',
        // Article metadata from Robaws IMPORTANT INFO
        'article_info',
        'update_date',
        'validity_date',
        // Port information in full Robaws format: "Antwerp (ANR), Belgium"
        'pol',
        'pod',
        'commodity_type',
        'is_mandatory',
        'mandatory_condition',
        'notes',
        // Standard Robaws article fields
        'sales_name',
        'brand',
        'barcode',
        'article_number',
        'sale_price',
        'cost_price',
        'sale_price_strategy',
        'cost_price_strategy',
        'margin',
        'weight_kg',
        'vat_tariff_id',
        'stock_article',
        'time_operation',
        'installation',
        'wappy',
        'image_id',
        'composite_items',
    ];

    protected $casts = [
        'applicable_routes' => 'array',
        'applicable_services' => 'array',
        'pricing_formula' => 'array',
        'profit_margins' => 'array',
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'is_parent_article' => 'boolean',
        'is_surcharge' => 'boolean',
        'is_hinterland_waiver' => 'boolean',
        'is_active' => 'boolean',
        'requires_manual_review' => 'boolean',
        'last_synced_at' => 'datetime',
        'last_modified_at' => 'datetime',
        // New metadata fields
        'is_parent_item' => 'boolean',
        'is_mandatory' => 'boolean',
        'update_date' => 'date',
        'validity_date' => 'date',
        // Standard Robaws field casts
        'sale_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'margin' => 'decimal:2',
        'weight_kg' => 'decimal:2',
        'stock_article' => 'boolean',
        'time_operation' => 'boolean',
        'installation' => 'boolean',
        'wappy' => 'boolean',
        'composite_items' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($article) {
            if (empty($article->article_code)) {
                $article->article_code = $article->article_name ? substr($article->article_name, 0, 20) : 'UNKNOWN';
            }
        });
        
    }

    /**
     * Scopes
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCarrier(Builder $query, string $carrierCode): Builder
    {
        // Match articles by shipping_line (case-insensitive)
        // Use database-agnostic case-insensitive matching
        $useIlike = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql';
        
        if ($useIlike) {
            return $query->where('shipping_line', 'ILIKE', '%' . $carrierCode . '%')
                         ->orWhereNull('shipping_line');
        } else {
            return $query->whereRaw('LOWER(shipping_line) LIKE ?', ['%' . strtolower($carrierCode) . '%'])
                         ->orWhereNull('shipping_line');
        }
    }

    public function scopeForService(Builder $query, string $serviceType): Builder
    {
        if ($transportMode = $this->mapQuotationServiceTypeToTransportMode($serviceType)) {
            $query->where('transport_mode', $transportMode);
        }

        return $query->where(function ($q) use ($serviceType) {
            $q->whereJsonContains('applicable_services', $serviceType)
              ->orWhere('service_type', $serviceType)
              ->orWhereNull('applicable_services');
        });
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Check if article is applicable for a specific carrier
     */
    public function isApplicableForCarrier(string $carrierCode): bool
    {
        if (empty($this->shipping_line)) {
            return true; // No restrictions
        }

        return stripos($this->shipping_line, $carrierCode) !== false;
    }

    /**
     * Check if article is applicable for a specific service
     */
    public function isApplicableForService(string $serviceType): bool
    {
        if (empty($this->applicable_services)) {
            return true; // No restrictions
        }

        return in_array($serviceType, $this->applicable_services);
    }

    /**
     * Get formatted price
     */
    public function getFormattedPriceAttribute(): string
    {
        if ($this->unit_price === null) {
            return 'Price on request';
        }

        return $this->currency . ' ' . number_format($this->unit_price, 2);
    }

    /**
     * Relationships
     */
    
    /**
     * Child articles (surcharges) for parent articles
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(
            RobawsArticleCache::class,
            'article_children',
            'parent_article_id',
            'child_article_id'
        )->withPivot(['sort_order', 'is_required', 'is_conditional', 'child_type', 'conditions', 'cost_type', 'default_quantity', 'default_cost_price', 'unit_type'])
          ->withTimestamps()
          ->orderBy('sort_order');
    }

    /**
     * Parent articles for child articles (surcharges)
     */
    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(
            RobawsArticleCache::class,
            'article_children',
            'child_article_id',
            'parent_article_id'
        )->withPivot(['sort_order', 'is_required', 'is_conditional', 'child_type', 'conditions', 'cost_type', 'default_quantity', 'default_cost_price', 'unit_type'])
          ->withTimestamps();
    }

    /**
     * Quotation requests this article is used in
     */
    public function quotationRequests(): BelongsToMany
    {
        return $this->belongsToMany(QuotationRequest::class, 'quotation_request_articles', 'article_cache_id', 'quotation_request_id')
            ->withPivot(['parent_article_id', 'item_type', 'quantity', 'unit_price', 'unit_type', 'selling_price', 'subtotal', 'currency', 'formula_inputs', 'calculated_price', 'notes'])
            ->withTimestamps();
    }

    /**
     * Relationship query modifiers for filtering children by child_type
     */
    public function mandatoryChildren(): BelongsToMany
    {
        return $this->children()->wherePivot('child_type', 'mandatory');
    }

    public function optionalChildren(): BelongsToMany
    {
        return $this->children()->wherePivot('child_type', 'optional');
    }

    public function conditionalChildren(): BelongsToMany
    {
        return $this->children()->wherePivot('child_type', 'conditional');
    }

    /**
     * Pricing Methods
     */
    
    /**
     * Get price for a specific pricing tier (NEW - supports discounts and markups)
     *
     * @param \App\Models\PricingTier $tier Pricing tier object with margin percentage
     * @param array|null $formulaInputs Optional formula inputs for CONSOL pricing
     * @return float Selling price with tier margin applied
     */
    public function getPriceForTier(\App\Models\PricingTier $tier, ?array $formulaInputs = null): float
    {
        // Get base price (from Robaws or formula calculation)
        $basePrice = $this->unit_price ?? 0;
        
        // Handle formula-based pricing (CONSOL services)
        if ($this->pricing_formula && $formulaInputs) {
            $basePrice = $this->calculateFormulaPrice($formulaInputs);
        }
        
        // Apply tier margin using the tier's calculateSellingPrice method
        // This supports negative margins (discounts) and positive margins (markups)
        $sellingPrice = $tier->calculateSellingPrice($basePrice);
        
        return round($sellingPrice, 2);
    }
    
    /**
     * Get price with profit margin for a specific customer role (LEGACY - backward compatibility)
     *
     * @param string $role Customer role (FORWARDER, HOLLANDICO, etc.)
     * @param array|null $formulaInputs Optional formula inputs for CONSOL pricing
     * @return float
     */
    public function getPriceForRole(string $role, ?array $formulaInputs = null): float
    {
        // Handle formula-based pricing (CONSOL services)
        if ($this->pricing_formula && $formulaInputs) {
            $formula = $this->pricing_formula;
            $oceanFreight = $formulaInputs['ocean_freight'] ?? 0;
            $divisor = $formula['divisor'] ?? 1;
            $fixedAmount = $formula['fixed_amount'] ?? 0;
            
            $basePrice = ($oceanFreight / $divisor) + $fixedAmount;
        } else {
            $basePrice = $this->unit_price ?? 0;
        }
        
        // Apply role-based profit margin
        $margins = $this->profit_margins ?? [];
        $marginPercent = $margins[$role] ?? config('quotation.profit_margins.by_role.' . $role) ?? config('quotation.profit_margins.default', 15);
        
        return $basePrice * (1 + ($marginPercent / 100));
    }

    /**
     * Calculate price from formula inputs
     *
     * @param array $formulaInputs
     * @return float
     */
    public function calculateFormulaPrice(array $formulaInputs): float
    {
        if (!$this->pricing_formula) {
            return $this->unit_price ?? 0;
        }

        $formula = $this->pricing_formula;
        $oceanFreight = $formulaInputs['ocean_freight'] ?? 0;
        $divisor = $formula['divisor'] ?? 1;
        $fixedAmount = $formula['fixed_amount'] ?? 0;
        
        return ($oceanFreight / $divisor) + $fixedAmount;
    }

    /**
     * Get child articles with pricing for a specific role
     *
     * @param string $role Customer role
     * @return Collection
     */
    public function getChildArticlesWithPricing(string $role): Collection
    {
        return $this->children->map(function ($child) use ($role) {
            return [
                'article' => $child,
                'price' => $child->getPriceForRole($role),
                'is_required' => $child->pivot->is_required,
                'is_conditional' => $child->pivot->is_conditional,
                'conditions' => $child->pivot->conditions,
            ];
        });
    }

    /**
     * Check if article is applicable for quantity
     *
     * @param int $quantity
     * @return bool
     */
    public function isApplicableForQuantity(int $quantity): bool
    {
        return $quantity >= $this->min_quantity && $quantity <= $this->max_quantity;
    }

    /**
     * Additional Scopes
     */
    
    /**
     * Scope for customer type
     */
    public function scopeForCustomerType(Builder $query, ?string $customerType): Builder
    {
        // customer_type removed from articles - it's a quotation property
        // This scope is now a no-op for backward compatibility
        return $query;
    }

    /**
     * Scope for quantity tier
     */
    public function scopeForQuantity(Builder $query, int $quantity): Builder
    {
        return $query->where('min_quantity', '<=', $quantity)
                     ->where('max_quantity', '>=', $quantity);
    }

    /**
     * Scope for parent articles only
     */
    public function scopeParentsOnly(Builder $query): Builder
    {
        return $query->where('is_parent_article', true);
    }

    /**
     * Scope for surcharges only
     */
    public function scopeSurchargesOnly(Builder $query): Builder
    {
        return $query->where('is_surcharge', true);
    }

    /**
     * Scope for articles requiring manual review
     */
    public function scopeRequiringReview(Builder $query): Builder
    {
        return $query->where('requires_manual_review', true);
    }

    /**
     * New Scopes for Article Metadata Filtering
     */
    
    /**
     * Scope for filtering by shipping line
     */
    public function scopeForShippingLine(Builder $query, string $shippingLine): Builder
    {
        return $query->where('shipping_line', $shippingLine);
    }

    /**
     * Scope for filtering by service type
     */
    public function scopeForServiceType(Builder $query, string $serviceType): Builder
    {
        if ($transportMode = $this->mapQuotationServiceTypeToTransportMode($serviceType)) {
            $query->where('transport_mode', $transportMode);
        }

        return $query->where(function ($q) use ($serviceType) {
            $q->whereJsonContains('applicable_services', $serviceType)
              ->orWhereNull('applicable_services');
        });
    }

    /**
     * Scope for filtering by POL terminal
     */
    public function scopeForPolTerminal(Builder $query, string $polTerminal): Builder
    {
        return $query->where('pol_terminal', $polTerminal);
    }

    /**
     * Scope for filtering articles by schedule metadata
     * Filters by carrier, service type, and POL terminal from schedule
     */
    public function scopeForSchedule(Builder $query, \App\Models\ShippingSchedule $schedule): Builder
    {
        $query->where(function ($q) use ($schedule) {
            // Filter by carrier/shipping line
            if ($schedule->carrier) {
                $q->where('shipping_line', 'LIKE', '%' . $schedule->carrier->name . '%');
            }
            
            // Filter by POL terminal if available
            if ($schedule->polPort && $schedule->polPort->terminal_code) {
                $q->orWhere('pol_terminal', $schedule->polPort->terminal_code);
            }
        });
        
        return $query;
    }

    /**
     * Scope for parent items only (items with composite surcharges)
     */
    public function scopeParentItems(Builder $query): Builder
    {
        return $query->where('is_parent_item', true);
    }

    /**
     * Scope for filtering articles valid as of a specific date
     */
    public function scopeValidAsOf(Builder $query, \Carbon\Carbon $date): Builder
    {
        return $query->where(function ($q) use ($date) {
            $q->whereNull('validity_date')
              ->orWhere('validity_date', '>=', $date->format('Y-m-d'));
        });
    }

    /**
     * Scope for filtering by commodity type
     */
    public function scopeForCommodityType(Builder $query, string $commodityType): Builder
    {
        return $query->where('commodity_type', $commodityType);
    }

    /**
     * Scope for filtering by POL and POD codes (exact match)
     */
    public function scopeForPolPodMatch(Builder $query, string $pol, string $pod): Builder
    {
        return $query->where('pol', $pol)
                     ->where('pod', $pod);
    }

    /**
     * Extract port code from POL/POD string
     * Examples: "Antwerp (ANR), Belgium" -> "ANR"
     *           "Dakar (DKR), Senegal" -> "DKR"
     *           "Conakry" -> null
     */
    private function extractPortCode(?string $portString): ?string
    {
        if (!$portString) {
            return null;
        }

        // Extract code from parentheses if present: "City (CODE), Country"
        if (preg_match('/\(([^)]+)\)/', $portString, $matches)) {
            return strtoupper(trim($matches[1]));
        }

        return null;
    }

    /**
     * Scope for filtering articles based on complete quotation context
     * This is the main method for smart article selection
     */
    public function scopeForQuotationContext(Builder $query, \App\Models\QuotationRequest $quotation): Builder
    {
        // #region agent log
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => 'RobawsArticleCache.php:530',
            'message' => 'scopeForQuotationContext entry',
            'data' => [
                'quotation_id' => $quotation->id,
                'quotation_pol' => $quotation->pol,
                'quotation_pod' => $quotation->pod,
            ],
            'timestamp' => time() * 1000
        ]) . "\n", FILE_APPEND);
        // #endregion

        // Use database-agnostic case-insensitive matching
        // PostgreSQL supports ILIKE, SQLite/MySQL use LOWER() with LIKE
        $useIlike = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql';

        // Only show parent items when dataset contains them; otherwise fall back to all active articles.
        $hasParentItems = static::query()
            ->where('is_parent_article', true)
            ->limit(1)
            ->exists();

        if ($hasParentItems) {
            $query->where('is_parent_article', true);
        }

        // Include non-surcharge parents when surcharge flag is missing, but always prefer surcharges when available.
        $hasParentSurcharges = static::query()
            ->where('is_parent_article', true)
            ->where('is_surcharge', true)
            ->limit(1)
            ->exists();

        if ($hasParentSurcharges) {
            $query->where(function ($q) use ($quotation, $useIlike) {
                // Prefer surcharge parent articles
                $q->where(function ($qq) {
                    $qq->where('is_surcharge', true)
                        ->orWhereNull('is_surcharge');
                });

                // Allow non-surcharge fallback only if no surcharge matches the route
                $q->orWhere(function ($qq) use ($quotation, $useIlike) {
                    $qq->where('is_surcharge', false)
                        ->where(function ($routeQuery) use ($quotation, $useIlike) {
                            if ($quotation->pol) {
                                $quotationPolCode = $this->extractPortCode($quotation->pol);
                                if ($quotationPolCode) {
                                    // Match by port code in parentheses
                                    if ($useIlike) {
                                        $routeQuery->where('pol', 'ILIKE', '%(' . $quotationPolCode . ')%')
                                            ->orWhere('pol', 'ILIKE', $quotation->pol);
                                    } else {
                                        $routeQuery->whereRaw('LOWER(pol) LIKE LOWER(?)', ['%(' . $quotationPolCode . ')%'])
                                            ->orWhereRaw('LOWER(TRIM(pol)) = LOWER(TRIM(?))', [$quotation->pol]);
                                    }
                                } else {
                                    // Exact string match if no code
                                    if ($useIlike) {
                                        $routeQuery->where('pol', 'ILIKE', $quotation->pol);
                                    } else {
                                        $routeQuery->whereRaw('LOWER(TRIM(pol)) = LOWER(TRIM(?))', [$quotation->pol]);
                                    }
                                }
                            }
                            if ($quotation->pod) {
                                $quotationPodCode = $this->extractPortCode($quotation->pod);
                                if ($quotationPodCode) {
                                    // Match by port code in parentheses
                                    if ($useIlike) {
                                        $routeQuery->where('pod', 'ILIKE', '%(' . $quotationPodCode . ')%')
                                            ->orWhere('pod', 'ILIKE', $quotation->pod);
                                    } else {
                                        $routeQuery->whereRaw('LOWER(pod) LIKE LOWER(?)', ['%(' . $quotationPodCode . ')%'])
                                            ->orWhereRaw('LOWER(TRIM(pod)) = LOWER(TRIM(?))', [$quotation->pod]);
                                    }
                                } else {
                                    // Exact string match if no code
                                    if ($useIlike) {
                                        $routeQuery->where('pod', 'ILIKE', $quotation->pod);
                                    } else {
                                        $routeQuery->whereRaw('LOWER(TRIM(pod)) = LOWER(TRIM(?))', [$quotation->pod]);
                                    }
                                }
                            }
                        });
                });
            });
        }

        $query->where('is_active', true)
              ->validAsOf(now());

        // Apply POL/POD filtering - EXACT MATCHING ONLY (100% match required)
        // Extract port codes and compare exactly, fall back to exact string match
        if ($quotation->pol && $quotation->pod) {
            $quotationPolCode = $this->extractPortCode($quotation->pol);
            $quotationPodCode = $this->extractPortCode($quotation->pod);

            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'G',
                'location' => 'RobawsArticleCache.php:617',
                'message' => 'Port code extraction',
                'data' => [
                    'quotation_pol' => $quotation->pol,
                    'quotation_pod' => $quotation->pod,
                    'extracted_pol_code' => $quotationPolCode,
                    'extracted_pod_code' => $quotationPodCode,
                ],
                'timestamp' => time() * 1000
            ]) . "\n", FILE_APPEND);
            // #endregion

            // Require both POL and POD to match exactly
            $query->where(function ($q) use ($quotation, $quotationPolCode, $useIlike) {
                if ($quotationPolCode) {
                    // Match by port code in parentheses (more flexible but still exact code match)
                    $q->where(function ($codeQuery) use ($quotationPolCode, $quotation, $useIlike) {
                        // Match if article POL contains the port code in parentheses
                        if ($useIlike) {
                            $codeQuery->where('pol', 'ILIKE', '%(' . $quotationPolCode . ')%')
                                ->orWhere('pol', 'ILIKE', $quotation->pol);
                        } else {
                            $codeQuery->whereRaw('LOWER(pol) LIKE LOWER(?)', ['%(' . $quotationPolCode . ')%'])
                                ->orWhereRaw('LOWER(TRIM(pol)) = LOWER(TRIM(?))', [$quotation->pol]);
                        }
                    });
                } else {
                    // No code available - exact string match
                    if ($useIlike) {
                        $q->where('pol', 'ILIKE', $quotation->pol);
                    } else {
                        $q->whereRaw('LOWER(TRIM(pol)) = LOWER(TRIM(?))', [$quotation->pol]);
                    }
                }
            })->where(function ($q) use ($quotation, $quotationPodCode, $useIlike) {
                if ($quotationPodCode) {
                    // Match by port code in parentheses
                    $q->where(function ($codeQuery) use ($quotationPodCode, $quotation, $useIlike) {
                        // Match if article POD contains the port code in parentheses
                        if ($useIlike) {
                            $codeQuery->where('pod', 'ILIKE', '%(' . $quotationPodCode . ')%')
                                ->orWhere('pod', 'ILIKE', $quotation->pod);
                        } else {
                            $codeQuery->whereRaw('LOWER(pod) LIKE LOWER(?)', ['%(' . $quotationPodCode . ')%'])
                                ->orWhereRaw('LOWER(TRIM(pod)) = LOWER(TRIM(?))', [$quotation->pod]);
                        }
                    });
                } else {
                    // No code available - exact string match
                    if ($useIlike) {
                        $q->where('pod', 'ILIKE', $quotation->pod);
                    } else {
                        $q->whereRaw('LOWER(TRIM(pod)) = LOWER(TRIM(?))', [$quotation->pod]);
                    }
                }
            });
        } elseif ($quotation->pol) {
            // Only POL specified - require exact POL match
            $quotationPolCode = $this->extractPortCode($quotation->pol);
            $query->where(function ($q) use ($quotation, $quotationPolCode, $useIlike) {
                if ($quotationPolCode) {
                    // Match by port code in parentheses (more flexible)
                    $q->where(function ($codeQuery) use ($quotationPolCode, $quotation, $useIlike) {
                        // Match if article POL contains the port code in parentheses
                        if ($useIlike) {
                            $codeQuery->where('pol', 'ILIKE', '%(' . $quotationPolCode . ')%')
                                ->orWhere('pol', 'ILIKE', $quotation->pol);
                        } else {
                            $codeQuery->whereRaw('LOWER(pol) LIKE LOWER(?)', ['%(' . $quotationPolCode . ')%'])
                                ->orWhereRaw('LOWER(TRIM(pol)) = LOWER(TRIM(?))', [$quotation->pol]);
                        }
                    });
                } else {
                    // No code available - exact string match
                    if ($useIlike) {
                        $q->where('pol', 'ILIKE', $quotation->pol);
                    } else {
                        $q->whereRaw('LOWER(TRIM(pol)) = LOWER(TRIM(?))', [$quotation->pol]);
                    }
                }
            });
        } elseif ($quotation->pod) {
            // Only POD specified - require exact POD match
            $quotationPodCode = $this->extractPortCode($quotation->pod);
            $query->where(function ($q) use ($quotation, $quotationPodCode, $useIlike) {
                if ($quotationPodCode) {
                    // Match by port code in parentheses
                    $q->where(function ($codeQuery) use ($quotationPodCode, $quotation, $useIlike) {
                        // Match if article POD contains the port code in parentheses
                        if ($useIlike) {
                            $codeQuery->where('pod', 'ILIKE', '%(' . $quotationPodCode . ')%')
                                ->orWhere('pod', 'ILIKE', $quotation->pod);
                        } else {
                            $codeQuery->whereRaw('LOWER(pod) LIKE LOWER(?)', ['%(' . $quotationPodCode . ')%'])
                                ->orWhereRaw('LOWER(TRIM(pod)) = LOWER(TRIM(?))', [$quotation->pod]);
                        }
                    });
                } else {
                    // No code available - exact string match
                    if ($useIlike) {
                        $q->where('pod', 'ILIKE', $quotation->pod);
                    } else {
                        $q->whereRaw('LOWER(TRIM(pod)) = LOWER(TRIM(?))', [$quotation->pod]);
                    }
                }
            });
        }

        // Apply transport mode filter derived from quotation service type
        if ($quotation->service_type) {
            $transportMode = $this->mapQuotationServiceTypeToTransportMode($quotation->service_type);
            $serviceTypeValue = Str::upper(str_replace(' ', '_', $quotation->service_type));

            $query->where(function ($q) use ($transportMode, $serviceTypeValue) {
                if ($transportMode) {
                    $q->where('transport_mode', $transportMode);
                    $q->orWhere('service_type', $serviceTypeValue);
                } else {
                    $q->where('service_type', $serviceTypeValue);
                }
            });
        }

        // Apply shipping line filter if schedule is selected - Include NULL shipping_line (universal articles)
        if ($quotation->selected_schedule_id && $quotation->selectedSchedule) {
            $schedule = $quotation->selectedSchedule;
            if ($schedule->carrier) {
                $query->where(function ($q) use ($schedule, $useIlike) {
                    $carrierName = $schedule->carrier->name;
                    // Extract base carrier name (e.g., "Grimaldi" from "Grimaldi GNET")
                    // This handles cases where article has "GRIMALDI LINES" but carrier is "Grimaldi GNET"
                    $baseCarrierName = explode(' ', $carrierName)[0]; // Get first word
                    
                    if ($useIlike) {
                        // Try exact match first, then fall back to base name, or NULL (universal articles)
                        $q->where('shipping_line', 'ILIKE', '%' . $carrierName . '%')
                          ->orWhere('shipping_line', 'ILIKE', '%' . $baseCarrierName . '%')
                          ->orWhereNull('shipping_line');
                    } else {
                        // Try exact match first, then fall back to base name, or NULL (universal articles)
                        $q->whereRaw('LOWER(shipping_line) LIKE ?', ['%' . strtolower($carrierName) . '%'])
                          ->orWhereRaw('LOWER(shipping_line) LIKE ?', ['%' . strtolower($baseCarrierName) . '%'])
                          ->orWhereNull('shipping_line');
                    }
                });
            }
        }

        // PHASE 4: Check for article mappings (ALLOWLIST strategy)
        $carrierId = null;
        $podPortId = null;
        $vehicleCategory = null;
        $categoryGroupId = null;
        $vesselName = null;
        $vesselClass = null;

        // Determine context inputs
        if ($quotation->selected_schedule_id && $quotation->selectedSchedule) {
            $schedule = $quotation->selectedSchedule;
            if ($schedule->carrier) {
                $carrierId = $schedule->carrier->id;
            }
            $vesselName = $schedule->vessel_name;
            $vesselClass = $schedule->vessel_class;
            
            // Get POD port ID from schedule
            if ($schedule->pod_id) {
                $podPortId = $schedule->pod_id;
            }
        }

        // Get vehicle category and derive category group from commodity items
        if ($quotation->commodityItems && $quotation->commodityItems->count() > 0) {
            // Get first commodity item's category (for now - could be enhanced to handle multiple)
            $firstItem = $quotation->commodityItems->first();
            $vehicleCategory = $firstItem->category ?? null;
            
            // Derive category group ID if vehicle category exists and carrier is known
            if ($vehicleCategory && $carrierId) {
                $member = \App\Models\CarrierCategoryGroupMember::whereHas('categoryGroup', function ($q) use ($carrierId) {
                    $q->where('carrier_id', $carrierId)->where('is_active', true);
                })
                ->where('vehicle_category', $vehicleCategory)
                ->where('is_active', true)
                ->first();
                
                $categoryGroupId = $member?->carrier_category_group_id;
            }
        }

        // Call resolver to get article mappings
        $mappings = collect([]);
        if ($carrierId) {
            $resolver = app(\App\Services\CarrierRules\CarrierRuleResolver::class);
            $mappings = $resolver->resolveArticleMappings(
                $carrierId,
                $podPortId,
                $vehicleCategory,
                $categoryGroupId,
                $vesselName,
                $vesselClass
            );
        }

        // If mappings exist, apply ALLOWLIST strategy
        if ($mappings->isNotEmpty()) {
            $mappedArticleIds = $mappings->pluck('article_id')->unique()->toArray();
            
            // Apply allowlist: only mapped articles + universal articles (commodity_type NULL)
            $query->where(function ($q) use ($mappedArticleIds) {
                $q->whereIn('id', $mappedArticleIds)
                  ->orWhereNull('commodity_type'); // Keep universal articles
            });
            
            // Skip commodity type filtering when mappings exist
            return $query;
        }

        // ELSE: Fallback to cleaned commodity type matching
        // Apply commodity type filter - STRICT when selected (NO NULL fallback)
        $commodityTypes = [];
        
        // Check simple commodity_type field first (used in Filament quotations)
        if ($quotation->commodity_type) {
            $commodityTypes[] = $this->mapQuotationCommodityTypeToArticle($quotation->commodity_type);
        }
        
        // Also check detailed commodityItems (used in customer/public quotations)
        if ($quotation->commodityItems && $quotation->commodityItems->count() > 0) {
            $itemTypes = $quotation->commodityItems->map(function ($item) {
                return $this->normalizeCommodityType($item);
            })->filter()->unique()->values()->toArray();
            
            $commodityTypes = array_merge($commodityTypes, $itemTypes);
        }
        
        // Filter by commodity type when selected, but always include universal articles (NULL commodity_type)
        if (!empty($commodityTypes)) {
            $commodityTypes = collect($commodityTypes)
                ->map(fn ($type) => Str::upper(trim($type)))
                ->flatMap(function ($type) {
                    // Map LM cargo types to article commodity types
                    // TRUCKHEAD, TRUCK, TRAILER, BUS are LM cargo and should also match articles
                    // that might be used for LM cargo (Big Van, Car, Small Van for some carriers/routes)
                    // Note: Only use article types that actually exist in database
                    // 'TRUCKHEAD' and 'HH' don't exist as article commodity types, so removed
                    return match ($type) {
                        'TRUCK' => ['TRUCK', 'LM CARGO', 'BIG VAN', 'CAR', 'SMALL VAN', 'BUS'],
                        'TRUCKHEAD' => ['LM CARGO', 'BIG VAN', 'CAR', 'SMALL VAN', 'TRUCK', 'BUS'],
                        'TRAILER' => ['LM CARGO', 'BIG VAN', 'CAR', 'SMALL VAN', 'TRUCK', 'BUS'],
                        'BUS' => ['BUS', 'LM CARGO', 'BIG VAN', 'CAR', 'SMALL VAN', 'TRUCK'],
                        default => [$type],
                    };
                })
                ->unique()
                ->values()
                ->toArray();

            \Log::debug('SmartArticleSelection commodity filter', [
                'quotation_id' => $quotation->id ?? null,
                'types' => $commodityTypes,
            ]);

            // #region agent log
            @file_put_contents(base_path('.cursor/debug.log'), json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C',
                'location' => 'RobawsArticleCache.php:827',
                'message' => 'Commodity type filter being applied',
                'data' => [
                    'commodity_types' => $commodityTypes,
                    'commodity_types_count' => count($commodityTypes),
                ],
                'timestamp' => time() * 1000
            ]) . "\n", FILE_APPEND);
            // #endregion

            $placeholders = implode(', ', array_fill(0, count($commodityTypes), '?'));
            // Include articles that match the commodity type OR have NULL commodity_type (universal articles)
            // Strict filtering: only show articles that match the expanded commodity types
            $query->where(function ($q) use ($commodityTypes, $placeholders) {
                $q->whereRaw("UPPER(TRIM(commodity_type)) IN ($placeholders)", $commodityTypes)
                  ->orWhereNull('commodity_type');
            });
        }
        // If no commodity selected, show all articles (existing behavior)

        // #region agent log
        $testQuery = clone $query;
        $testCount = $testQuery->count();
        
        // Check if article 230 matches
        $article230Query = clone $query;
        $article230Matches = $article230Query->where('id', 230)->exists();
        
        @file_put_contents(base_path('.cursor/debug.log'), json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => 'RobawsArticleCache.php:831',
            'message' => 'scopeForQuotationContext final query count',
            'data' => [
                'query_count' => $testCount,
                'quotation_pol' => $quotation->pol,
                'quotation_pod' => $quotation->pod,
                'article_230_matches' => $article230Matches,
            ],
            'timestamp' => time() * 1000
        ]) . "\n", FILE_APPEND);
        // #endregion

        return $query;
    }

    /**
     * Map quotation commodity_type field to article commodity_type
     * Used for simple commodity field in Filament quotations
     */
    private function mapQuotationCommodityTypeToArticle(?string $quotationCommodityType): ?string
    {
        if (!$quotationCommodityType) {
            return null;
        }
        
        // Map quotation commodity types to article commodity types
        $mapping = [
            'cars' => 'Car',
            'general_goods' => 'General Cargo',
            'personal_goods' => 'General Cargo',
            'motorcycles' => 'Motorcycle',
            'trucks' => 'Truck',
            'machinery' => 'Machinery',
            'breakbulk' => 'Break Bulk',
        ];
        
        return $mapping[$quotationCommodityType] ?? null;
    }

    /**
     * Map quotation service type (e.g., RORO_EXPORT) to article transport mode (e.g., RORO)
     */
    private function mapQuotationServiceTypeToTransportMode(?string $serviceType): ?string
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
     * Normalize commodity type from commodity item
     */
    private function normalizeCommodityType($commodityItem): ?string
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
     */
    private function getVehicleCategoryMapping($commodityItem): ?string
    {
        $category = $commodityItem->category ?? null;

        // Map vehicle categories to Robaws types
        $vehicleMapping = [
            'car' => 'Car',
            'suv' => 'SUV',
            'small_van' => 'Small Van',
            'big_van' => 'Big Van',
            'truck' => 'Truck',
            'truckhead' => 'Truckhead',
            'trailer' => 'Trailer',
            'bus' => 'Bus',
            'motorcycle' => 'Motorcycle',
        ];

        // Return null if category not found (don't default to Car)
        return $vehicleMapping[$category] ?? null;
    }

    /**
     * Get resolved POD port using PortResolutionService
     * If pod_code exists -> resolveOne(pod_code)
     * Else if pod string exists -> resolveOne(pod)
     * 
     * @return Port|null
     */
    public function getResolvedPodPort(): ?Port
    {
        $resolver = app(\App\Services\Ports\PortResolutionService::class);
        
        // Try pod_code first
        if (!empty($this->pod_code)) {
            $port = $resolver->resolveOne($this->pod_code);
            if ($port) {
                return $port;
            }
        }
        
        // Fallback to pod string
        if (!empty($this->pod)) {
            return $resolver->resolveOne($this->pod);
        }
        
        return null;
    }
}

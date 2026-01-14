<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use App\Models\Port;

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
        'shipping_carrier_id',
        'service_type',
        'transport_mode',
        'pol_terminal',
        'is_parent_item',
        'article_type',
        'cost_side',
        'pol_code',
        'pod_code',
        'pol_port_id',
        'pod_port_id',
        // Article metadata from Robaws IMPORTANT INFO
        'article_info',
        'update_date',
        'validity_date',
        // Override columns for date management
        'update_date_override',
        'validity_date_override',
        'dates_override_source',
        'dates_override_at',
        'last_pushed_dates_at',
        'last_pushed_update_date',
        'last_pushed_validity_date',
        'last_pushed_to_robaws_at',
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
        'cost_price',
        'sale_price_strategy',
        'cost_price_strategy',
        'purchase_price_breakdown',
        'max_dimensions_breakdown',
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
        // Override fields
        'update_date_override' => 'date',
        'validity_date_override' => 'date',
        'dates_override_at' => 'datetime',
        'last_pushed_dates_at' => 'datetime',
        'last_pushed_update_date' => 'date',
        'last_pushed_validity_date' => 'date',
        'last_pushed_to_robaws_at' => 'datetime',
        // Standard Robaws field casts
        'cost_price' => 'decimal:2',
        'margin' => 'decimal:2',
        'weight_kg' => 'decimal:2',
        'stock_article' => 'boolean',
        'time_operation' => 'boolean',
        'installation' => 'boolean',
        'wappy' => 'boolean',
        'composite_items' => 'array',
        'purchase_price_breakdown' => 'array',
        'max_dimensions_breakdown' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($article) {
            if (empty($article->article_code)) {
                $article->article_code = $article->article_name ? substr($article->article_name, 0, 20) : 'UNKNOWN';
            }
        });

        // Sync is_parent_article from is_parent_item (source of truth)
        static::saving(function ($article) {
            // Always sync is_parent_article from is_parent_item
            // Treat NULL is_parent_item as false
            $article->is_parent_article = $article->is_parent_item ?? false;
        });

        // Auto-sync max dimensions and purchase price breakdown when article is created/updated
        static::saved(function ($article) {
            // Track if critical fields changed
            $criticalFieldsChanged = $article->wasRecentlyCreated 
                || $article->wasChanged('shipping_carrier_id')
                || $article->wasChanged('pod_port_id')
                || $article->wasChanged('commodity_type');
            
            // Only sync if article has required fields
            if ($criticalFieldsChanged && $article->shipping_carrier_id && $article->pod_port_id) {
                // Sync max dimensions
                try {
                    $maxDimensionsService = app(\App\Services\CarrierRules\MaxDimensionsSyncService::class);
                    $maxDimensionsService->syncActiveRuleForArticle($article);
                } catch (\Exception $e) {
                    \Log::warning('Failed to sync max dimensions for article', [
                        'article_id' => $article->id,
                        'error' => $e->getMessage()
                    ]);
                }
                
                // Sync purchase price breakdown
                try {
                    $purchasePriceService = app(\App\Services\Pricing\PurchasePriceSyncService::class);
                    $purchasePriceService->syncActiveTariffForArticle($article);
                } catch (\Exception $e) {
                    \Log::warning('Failed to sync purchase price breakdown for article', [
                        'article_id' => $article->id,
                        'error' => $e->getMessage()
                    ]);
                }
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

    public function scopeForCarrier(Builder $query, int|string $carrier): Builder
    {
        // If numeric, treat as carrier ID
        if (is_numeric($carrier)) {
            return $query->where('shipping_carrier_id', $carrier)
                         ->orWhereNull('shipping_carrier_id');
        }
        
        // String: lookup by code or name via relationship
        return $query->whereHas('shippingCarrier', function ($q) use ($carrier) {
            $q->where('code', $carrier)
              ->orWhereRaw('LOWER(name) LIKE ?', ['%' . strtolower($carrier) . '%']);
        })->orWhereNull('shipping_carrier_id');
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
    public function isApplicableForCarrier(int|string $carrier): bool
    {
        // If no carrier specified, article is universal
        if (!$this->shipping_carrier_id && empty($this->shipping_line)) {
            return true; // No restrictions
        }

        // PRIORITY 1: Check shipping_carrier_id first (preferred method)
        if ($this->shipping_carrier_id) {
            // If numeric, check by ID directly
            if (is_numeric($carrier)) {
                return $this->shipping_carrier_id == $carrier;
            }
            
            // String: check via relationship (load if not already loaded)
            $carrierModel = $this->shippingCarrier;
            if ($carrierModel) {
                return strcasecmp($carrierModel->code, $carrier) === 0
                    || stripos($carrierModel->name, $carrier) !== false;
            }
        }

        // PRIORITY 2: Fallback to shipping_line string matching (backward compatibility)
        if (!empty($this->shipping_line)) {
            return stripos($this->shipping_line, $carrier) !== false;
        }

        return false;
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
     * Shipping carrier for this article
     */
    public function shippingCarrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class, 'shipping_carrier_id');
    }

    /**
     * POL port for this article
     */
    public function polPort(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'pol_port_id');
    }

    /**
     * POD port for this article
     */
    public function podPort(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'pod_port_id');
    }

    /**
     * Get shipping_line attribute - computed from shipping_carrier_id for consistency
     * If shipping_carrier_id is set, return carrier name; otherwise fallback to stored value
     */
    public function getShippingLineAttribute($value)
    {
        // If shipping_carrier_id is set, use carrier name
        if ($this->shipping_carrier_id && $this->relationLoaded('shippingCarrier')) {
            return $this->shippingCarrier->name ?? $value;
        }
        
        // If shipping_carrier_id is set but relationship not loaded, load it
        if ($this->shipping_carrier_id) {
            $carrier = $this->shippingCarrier;
            if ($carrier) {
                return $carrier->name;
            }
        }
        
        // Fallback to stored value for backward compatibility
        return $value;
    }

    /**
     * Get POL display attribute - shows port name and code if resolved, otherwise falls back to raw code or formatted string
     * This does NOT override the real pol column accessor
     */
    public function getPolDisplayAttribute(): string
    {
        if ($this->polPort) {
            return "{$this->polPort->name} ({$this->polPort->code})";
        }
        return $this->pol_code ?? $this->pol ?? '—';
    }

    /**
     * Get POD display attribute - shows port name and code if resolved, otherwise falls back to raw code or formatted string
     * This does NOT override the real pod column accessor
     */
    public function getPodDisplayAttribute(): string
    {
        if ($this->podPort) {
            return "{$this->podPort->name} ({$this->podPort->code})";
        }
        return $this->pod_code ?? $this->pod ?? '—';
    }

    /**
     * Get POL code resolved attribute - returns port code from FK if available, otherwise raw code
     * This does NOT override the real pol_code column accessor
     */
    public function getPolCodeResolvedAttribute(): ?string
    {
        return $this->polPort?->code ?? $this->pol_code;
    }

    /**
     * Get POD code resolved attribute - returns port code from FK if available, otherwise raw code
     * This does NOT override the real pod_code column accessor
     */
    public function getPodCodeResolvedAttribute(): ?string
    {
        return $this->podPort?->code ?? $this->pod_code;
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
        return $query->where('is_parent_item', true);
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
     * Accepts carrier ID, carrier code, or shipping line name
     * Prefers shipping_carrier_id over shipping_line for consistency
     */
    public function scopeForShippingLine(Builder $query, string|int $shippingLine): Builder
    {
        // If numeric, treat as carrier ID
        if (is_numeric($shippingLine)) {
            return $query->where('shipping_carrier_id', $shippingLine)
                         ->orWhereNull('shipping_carrier_id');
        }
        
        // Try to find carrier by code or name first
        $carrier = \App\Services\Carrier\CarrierLookupService::class;
        $carrierLookup = app($carrier);
        $foundCarrier = $carrierLookup->findByCodeOrName($shippingLine);
        
        if ($foundCarrier) {
            // Use shipping_carrier_id if carrier found
            return $query->where(function ($q) use ($foundCarrier) {
                $q->where('shipping_carrier_id', $foundCarrier->id)
                  ->orWhereNull('shipping_carrier_id'); // Allow universal articles
            });
        }
        
        // Fallback to shipping_line for backward compatibility
        return $query->where(function ($q) use ($shippingLine) {
            $q->where('shipping_line', 'LIKE', '%' . $shippingLine . '%')
              ->orWhereNull('shipping_line');
        });
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
     * Prefers shipping_carrier_id over shipping_line for consistency
     */
    public function scopeForSchedule(Builder $query, \App\Models\ShippingSchedule $schedule): Builder
    {
        $query->where(function ($q) use ($schedule) {
            // Filter by carrier - prefer shipping_carrier_id
            if ($schedule->carrier_id) {
                $q->where(function ($qq) use ($schedule) {
                    $qq->where('shipping_carrier_id', $schedule->carrier_id)
                       ->orWhereNull('shipping_carrier_id'); // Allow universal articles
                });
            } elseif ($schedule->carrier) {
                // Fallback to shipping_line if carrier_id not available
                $q->where(function ($qq) use ($schedule) {
                    $qq->where('shipping_line', 'LIKE', '%' . $schedule->carrier->name . '%')
                       ->orWhereNull('shipping_line');
                });
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
     * Filter carrier article mappings to only include those that match the requested port
     *
     * @param \Illuminate\Support\Collection $mappings
     * @param int|null $portId
     * @param array $portGroupIds
     * @return \Illuminate\Support\Collection
     */
    private function filterMappingsByPort($mappings, ?int $portId, array $portGroupIds = []): Collection
    {
        if ($portId === null) {
            // No port specified, return all mappings (including port-specific ones)
            return $mappings;
        }

        $filtered = $mappings->filter(function ($mapping) use ($portId, $portGroupIds) {
            $mappingPortIds = $mapping->port_ids ?? [];
            $mappingPortGroupIds = $mapping->port_group_ids ?? [];

            // Include global mappings (port_ids=null and port_group_ids=null)
            if (empty($mappingPortIds) && empty($mappingPortGroupIds)) {
                return true;
            }

            // Include mappings that explicitly contain the requested port
            // Use non-strict comparison to handle type mismatches (int vs string)
            if (!empty($mappingPortIds) && in_array($portId, $mappingPortIds, false)) {
                return true;
            }

            // Include mappings that match via port group ONLY if they don't have specific port_ids
            // This prevents including mappings for other ports that happen to be in the same port group
            if (empty($mappingPortIds) && !empty($mappingPortGroupIds) && !empty($portGroupIds)) {
                foreach ($portGroupIds as $groupId) {
                    if (in_array($groupId, $mappingPortGroupIds, false)) {
                        return true;
                    }
                }
            }

            // Exclude mappings for other specific ports
            return false;
        });

        return $filtered;
    }

    /**
     * Scope for filtering articles based on complete quotation context
     * This is the main method for smart article selection
     */
    public function scopeForQuotationContext(Builder $query, \App\Models\QuotationRequest $quotation): Builder
    {
        // #region agent log
        @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => 'RobawsArticleCache.php:786',
            'message' => 'scopeForQuotationContext entry',
            'data' => [
                'quotation_id' => $quotation->id ?? null,
                'request_number' => $quotation->request_number ?? null,
                'pol' => $quotation->pol ?? null,
                'pod' => $quotation->pod ?? null,
                'service_type' => $quotation->service_type ?? null,
                'selected_schedule_id' => $quotation->selected_schedule_id ?? null,
                'commodity_type' => $quotation->commodity_type ?? null,
                'has_commodity_items' => $quotation->commodityItems ? $quotation->commodityItems->count() : 0,
            ],
            'timestamp' => time() * 1000
        ]) . "\n", FILE_APPEND);
        // #endregion
        
        // Use database-agnostic case-insensitive matching
        // PostgreSQL supports ILIKE, SQLite/MySQL use LOWER() with LIKE
        $useIlike = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql';

        // Only show parent items when dataset contains them; otherwise fall back to all active articles.
        $hasParentItems = static::query()
            ->where('is_parent_item', true)
            ->limit(1)
            ->exists();

        // #region agent log
        @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'E',
            'location' => 'RobawsArticleCache.php:793',
            'message' => 'Parent items check',
            'data' => [
                'quotation_id' => $quotation->id ?? null,
                'has_parent_items' => $hasParentItems,
            ],
            'timestamp' => time() * 1000
        ]) . "\n", FILE_APPEND);
        // #endregion

        if ($hasParentItems) {
            $query->where('is_parent_item', true);
        }

        // Include non-surcharge parents when surcharge flag is missing, but always prefer surcharges when available.
        $hasParentSurcharges = static::query()
            ->where('is_parent_item', true)
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
                        }
                    });
                    });
                }
            });
        }

        $query->where('is_active', true);

        // PHASE 4: Check for article mappings EARLY (before validity/POL/POD filtering) to allow bypassing
        // Initialize variables early to prevent null errors
        $carrierId = null;
        $podPortId = null;
        $vehicleCategory = null;
        $categoryGroupId = null;
        $vesselName = null;
        $vesselClass = null;
        $mappings = collect([]);
        $mappedArticleIds = []; // Initialize to empty array to prevent count() errors

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

        // Get vehicle categories from ALL commodity items (not just first)
        // This ensures we get mappings for all types (CAR, LM, etc.)
        $vehicleCategories = [];
        $categoryGroupIds = [];
        $allMappings = collect([]);
        $mappedArticleIds = [];
        
        if ($quotation->commodityItems && $quotation->commodityItems->count() > 0) {
            // Collect all unique categories from commodity items
            $vehicleCategories = $quotation->commodityItems
                ->pluck('category')
                ->filter()
                ->unique()
                ->values()
                ->toArray();
            
            // Derive category group IDs for each vehicle category
            if ($carrierId && !empty($vehicleCategories)) {
                $members = \App\Models\CarrierCategoryGroupMember::whereHas('categoryGroup', function ($q) use ($carrierId) {
                    $q->where('carrier_id', $carrierId)->where('is_active', true);
                })
                ->whereIn('vehicle_category', $vehicleCategories)
                ->where('is_active', true)
                ->get();
                
                $categoryGroupIds = $members->pluck('carrier_category_group_id')->unique()->filter()->values()->toArray();
            }
            
            // For backward compatibility, also set first item's category
            $firstItem = $quotation->commodityItems->first();
            $vehicleCategory = $firstItem->category ?? null;
            
            if ($vehicleCategory && $carrierId && empty($categoryGroupIds)) {
                $member = \App\Models\CarrierCategoryGroupMember::whereHas('categoryGroup', function ($q) use ($carrierId) {
                    $q->where('carrier_id', $carrierId)->where('is_active', true);
                })
                ->where('vehicle_category', $vehicleCategory)
                ->where('is_active', true)
                ->first();
                
                if ($member) {
                    $categoryGroupIds[] = $member->carrier_category_group_id;
                }
            }
        }

        // Call resolver to get article mappings EARLY (before POL/POD filtering)
        // Get mappings for ALL vehicle categories, not just the first one
        if ($carrierId) {
            $resolver = app(\App\Services\CarrierRules\CarrierRuleResolver::class);
            
            // Map vehicle categories to category group IDs for LM cargo
            // LM cargo articles use category_group_ids instead of vehicle_categories
            $lmCategoryGroupIds = [];
            if (in_array('truck', $vehicleCategories) || 
                in_array('trailer', $vehicleCategories) || 
                in_array('truckhead', $vehicleCategories)) {
                $lmCargoTrucks = \App\Models\CarrierCategoryGroup::where('carrier_id', $carrierId)
                    ->where('code', 'LM_CARGO_TRUCKS')
                    ->first();
                $lmCargoTrailers = \App\Models\CarrierCategoryGroup::where('carrier_id', $carrierId)
                    ->where('code', 'LM_CARGO_TRAILERS')
                    ->first();
                
                if ($lmCargoTrucks) {
                    $lmCategoryGroupIds[] = $lmCargoTrucks->id;
                }
                if ($lmCargoTrailers) {
                    $lmCategoryGroupIds[] = $lmCargoTrailers->id;
                }
            }
            
            // Get mappings for each unique vehicle category
            if (!empty($vehicleCategories)) {
                foreach ($vehicleCategories as $category) {
                    $categoryMappings = $resolver->resolveArticleMappings(
                        $carrierId,
                        $podPortId,
                        $category,
                        null, // Don't filter by category group - get all mappings for this category
                        $vesselName,
                        $vesselClass
                    );
                    
                    // Filter mappings to only include those that match the requested port
                    if ($podPortId !== null) {
                        $portGroupIds = $resolver->resolvePortGroupIdsForPort($carrierId, $podPortId);
                        $categoryMappings = $this->filterMappingsByPort($categoryMappings, $podPortId, $portGroupIds);
                    }
                    
                    $allMappings = $allMappings->merge($categoryMappings);
                }
            }
            
            // Also check category group mappings for ALL vehicle categories
            // This finds mappings that use category_group_ids instead of vehicle_categories
            // This is needed because some carriers (like Grimaldi) use category_group_ids for car mappings
            if (!empty($categoryGroupIds)) {
                foreach ($categoryGroupIds as $categoryGroupId) {
                    $categoryMappings = $resolver->resolveArticleMappings(
                        $carrierId,
                        $podPortId,
                        null, // No vehicle category when using category groups
                        $categoryGroupId,
                        $vesselName,
                        $vesselClass
                    );
                    
                    if ($podPortId !== null) {
                        $portGroupIds = $resolver->resolvePortGroupIdsForPort($carrierId, $podPortId);
                        $categoryMappings = $this->filterMappingsByPort($categoryMappings, $podPortId, $portGroupIds);
                    }
                    
                    $allMappings = $allMappings->merge($categoryMappings);
                }
            }
            
            // Also check category group mappings for LM cargo (truck/trailer/truckhead)
            // This finds mappings that use category_group_ids for LM cargo specifically
            if (!empty($lmCategoryGroupIds)) {
                foreach ($lmCategoryGroupIds as $categoryGroupId) {
                    // Skip if already processed above
                    if (in_array($categoryGroupId, $categoryGroupIds)) {
                        continue;
                    }
                    
                    $categoryMappings = $resolver->resolveArticleMappings(
                        $carrierId,
                        $podPortId,
                        null, // No vehicle category when using category groups
                        $categoryGroupId,
                        $vesselName,
                        $vesselClass
                    );
                    
                    if ($podPortId !== null) {
                        $portGroupIds = $resolver->resolvePortGroupIdsForPort($carrierId, $podPortId);
                        $categoryMappings = $this->filterMappingsByPort($categoryMappings, $podPortId, $portGroupIds);
                    }
                    
                    $allMappings = $allMappings->merge($categoryMappings);
                }
            }
            
            // Also get universal mappings (no vehicle category filter)
            $universalMappings = $resolver->resolveArticleMappings(
                $carrierId,
                $podPortId,
                null, // No vehicle category = universal mappings
                null,
                $vesselName,
                $vesselClass
            );
            
            if ($podPortId !== null) {
                $portGroupIds = $resolver->resolvePortGroupIdsForPort($carrierId, $podPortId);
                $universalMappings = $this->filterMappingsByPort($universalMappings, $podPortId, $portGroupIds);
            }
            
            $allMappings = $allMappings->merge($universalMappings);
            
            // Remove duplicates and get unique article IDs
            $mappedArticleIds = $allMappings->pluck('article_id')->unique()->toArray();
            
            // #region agent log
            @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A',
                'location' => 'RobawsArticleCache.php:1022',
                'message' => 'Mappings calculated',
                'data' => [
                    'quotation_id' => $quotation->id ?? null,
                    'carrier_id' => $carrierId ?? null,
                    'all_mappings_count' => $allMappings->count(),
                    'mapped_article_ids' => $mappedArticleIds,
                    'mapped_article_ids_count' => count($mappedArticleIds),
                    'vehicle_categories' => $vehicleCategories,
                ],
                'timestamp' => time() * 1000
            ]) . "\n", FILE_APPEND);
            // #endregion
        } else {
            // #region agent log
            @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C',
                'location' => 'RobawsArticleCache.php:1024',
                'message' => 'No carrier ID - mappings not calculated',
                'data' => [
                    'quotation_id' => $quotation->id ?? null,
                    'carrier_id' => $carrierId ?? null,
                ],
                'timestamp' => time() * 1000
            ]) . "\n", FILE_APPEND);
            // #endregion
        }

        // Apply validity date filter
        $query->validAsOf(now());

        // Apply POL/POD filtering - EXACT MATCHING ONLY (100% match required)
        // BUT: If article mappings exist, allow mapped articles to bypass strict POL/POD matching
        // Extract port codes and compare exactly, fall back to exact string match
        $quotationPolCode = null;
        $quotationPodCode = null;
        if ($quotation->pol && $quotation->pod) {
            $quotationPolCode = $this->extractPortCode($quotation->pol);
            $quotationPodCode = $this->extractPortCode($quotation->pod);
            
            // #region agent log
            @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'D',
                'location' => 'RobawsArticleCache.php:905',
                'message' => 'POL/POD filtering',
                'data' => [
                    'quotation_id' => $quotation->id ?? null,
                    'quotation_pol' => $quotation->pol ?? null,
                    'quotation_pod' => $quotation->pod ?? null,
                    'extracted_pol_code' => $quotationPolCode ?? null,
                    'extracted_pod_code' => $quotationPodCode ?? null,
                    'has_mappings' => !empty($mappedArticleIds),
                    'mapped_article_ids_count' => count($mappedArticleIds),
                ],
                'timestamp' => time() * 1000
            ]) . "\n", FILE_APPEND);
            // #endregion

            // Require both POL and POD to match exactly, BUT allow mapped articles to bypass
            // IMPORTANT: When mappings exist, ONLY show mapped articles (don't fall back to POL/POD matching)
            $query->where(function ($q) use ($quotation, $quotationPolCode, $quotationPodCode, $useIlike, $mappedArticleIds) {
                // #region agent log
                @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'A',
                    'location' => 'RobawsArticleCache.php:1053',
                    'message' => 'POL/POD filter closure - mappedArticleIds check',
                    'data' => [
                        'mapped_article_ids_count' => count($mappedArticleIds),
                        'mapped_article_ids' => $mappedArticleIds,
                        'is_empty' => empty($mappedArticleIds),
                    ],
                    'timestamp' => time() * 1000
                ]) . "\n", FILE_APPEND);
                // #endregion
                
                // If mappings exist, ONLY show mapped articles (don't fall back to POL/POD matching)
                if (!empty($mappedArticleIds)) {
                    $q->whereIn('id', $mappedArticleIds);
                    
                    // #region agent log
                    @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
                        'sessionId' => 'debug-session',
                        'runId' => 'run1',
                        'hypothesisId' => 'B',
                        'location' => 'RobawsArticleCache.php:1056',
                        'message' => 'POL/POD bypass applied - ONLY mapped articles shown',
                        'data' => [
                            'mapped_article_ids' => $mappedArticleIds,
                        ],
                        'timestamp' => time() * 1000
                    ]) . "\n", FILE_APPEND);
                    // #endregion
                } else {
                    // No mappings exist - fall back to POL/POD matching
                    $q->where(function ($polPodQuery) use ($quotation, $quotationPolCode, $quotationPodCode, $useIlike) {
                    // POL matching
                    $polPodQuery->where(function ($polQuery) use ($quotation, $quotationPolCode, $useIlike) {
                        if ($quotationPolCode) {
                            // Match by port code in parentheses (more flexible but still exact code match)
                            $polQuery->where(function ($codeQuery) use ($quotationPolCode, $quotation, $useIlike) {
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
                                $polQuery->where('pol', 'ILIKE', $quotation->pol);
                            } else {
                                $polQuery->whereRaw('LOWER(TRIM(pol)) = LOWER(TRIM(?))', [$quotation->pol]);
                            }
                        }
                    });
                    
                    // POD matching
                    $polPodQuery->where(function ($podQuery) use ($quotation, $quotationPodCode, $useIlike) {
                        if ($quotationPodCode) {
                            // Match by port code in parentheses
                            $podQuery->where(function ($codeQuery) use ($quotationPodCode, $quotation, $useIlike) {
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
                                $podQuery->where('pod', 'ILIKE', $quotation->pod);
                            } else {
                                $podQuery->whereRaw('LOWER(TRIM(pod)) = LOWER(TRIM(?))', [$quotation->pod]);
                            }
                        }
                    });
                    });
                }
            });
        } elseif ($quotation->pol) {
            // Only POL specified - require exact POL match, BUT allow mapped articles to bypass
            $quotationPolCode = $this->extractPortCode($quotation->pol);
            $query->where(function ($q) use ($quotation, $quotationPolCode, $useIlike, $mappedArticleIds) {
                // If mappings exist, ONLY show mapped articles (don't fall back to POL matching)
                if (!empty($mappedArticleIds)) {
                    $q->whereIn('id', $mappedArticleIds);
                } else {
                    // No mappings exist - fall back to POL matching
                    $q->where(function ($polQuery) use ($quotation, $quotationPolCode, $useIlike) {
                    if ($quotationPolCode) {
                        // Match by port code in parentheses (more flexible)
                        $polQuery->where(function ($codeQuery) use ($quotationPolCode, $quotation, $useIlike) {
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
                            $polQuery->where('pol', 'ILIKE', $quotation->pol);
                        } else {
                            $polQuery->whereRaw('LOWER(TRIM(pol)) = LOWER(TRIM(?))', [$quotation->pol]);
                        }
                    }
                    });
                }
            });
        } elseif ($quotation->pod) {
            // Only POD specified - require exact POD match, BUT allow mapped articles to bypass
            $quotationPodCode = $this->extractPortCode($quotation->pod);
            $query->where(function ($q) use ($quotation, $quotationPodCode, $useIlike, $mappedArticleIds) {
                // If mappings exist, ONLY show mapped articles (don't fall back to POD matching)
                if (!empty($mappedArticleIds)) {
                    $q->whereIn('id', $mappedArticleIds);
                } else {
                    // No mappings exist - fall back to POD matching
                    $q->where(function ($podQuery) use ($quotation, $quotationPodCode, $useIlike) {
                    if ($quotationPodCode) {
                        // Match by port code in parentheses
                        $podQuery->where(function ($codeQuery) use ($quotationPodCode, $quotation, $useIlike) {
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
                            $podQuery->where('pod', 'ILIKE', $quotation->pod);
                        } else {
                            $podQuery->whereRaw('LOWER(TRIM(pod)) = LOWER(TRIM(?))', [$quotation->pod]);
                        }
                    }
                    });
                }
            });
        }

        // Apply transport mode filter derived from quotation service type
        // BUT: Allow mapped articles to bypass this filter
        if ($quotation->service_type) {
            $transportMode = $this->mapQuotationServiceTypeToTransportMode($quotation->service_type);
            $serviceTypeValue = Str::upper(str_replace(' ', '_', $quotation->service_type));

            // #region agent log
            $articlesBeforeTransportFilter = (clone $query)->get();
            @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'D',
                'location' => 'RobawsArticleCache.php:1178',
                'message' => 'Before transport mode filter',
                'data' => [
                    'quotation_id' => $quotation->id ?? null,
                    'service_type' => $quotation->service_type,
                    'transport_mode' => $transportMode,
                    'service_type_value' => $serviceTypeValue,
                    'articles_count' => $articlesBeforeTransportFilter->count(),
                ],
                'timestamp' => time() * 1000
            ]) . "\n", FILE_APPEND);
            // #endregion

            $query->where(function ($q) use ($transportMode, $serviceTypeValue, $mappedArticleIds) {
                // Allow mapped articles to bypass transport mode filter
                if (!empty($mappedArticleIds)) {
                    $q->whereIn('id', $mappedArticleIds);
                }
                
                // Also include articles that match transport mode
                $q->orWhere(function ($transportQuery) use ($transportMode, $serviceTypeValue) {
                    if ($transportMode) {
                        $transportQuery->where('transport_mode', $transportMode);
                        $transportQuery->orWhere('service_type', $serviceTypeValue);
                    } else {
                        $transportQuery->where('service_type', $serviceTypeValue);
                    }
                });
            });
            
            // #region agent log
            $articlesAfterTransportFilter = (clone $query)->get();
            @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'D',
                'location' => 'RobawsArticleCache.php:1191',
                'message' => 'After transport mode filter',
                'data' => [
                    'articles_count' => $articlesAfterTransportFilter->count(),
                    'articles_ids' => $articlesAfterTransportFilter->pluck('id')->toArray(),
                ],
                'timestamp' => time() * 1000
            ]) . "\n", FILE_APPEND);
            // #endregion
        }

        // Apply shipping line filter if schedule is selected - Include NULL shipping_carrier_id (universal articles)
        if ($quotation->selected_schedule_id && $quotation->selectedSchedule) {
            $schedule = $quotation->selectedSchedule;
            if ($schedule->carrier_id) {
                // #region agent log
                $articlesBeforeCarrierFilter = (clone $query)->get();
                @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'D',
                    'location' => 'RobawsArticleCache.php:1194',
                    'message' => 'Before carrier filter',
                    'data' => [
                        'quotation_id' => $quotation->id ?? null,
                        'schedule_id' => $schedule->id ?? null,
                        'carrier_id' => $schedule->carrier_id ?? null,
                        'articles_count' => $articlesBeforeCarrierFilter->count(),
                    ],
                    'timestamp' => time() * 1000
                ]) . "\n", FILE_APPEND);
                // #endregion
                
                $query->where(function ($q) use ($schedule, $mappedArticleIds) {
                    // Allow mapped articles to bypass carrier filter
                    if (!empty($mappedArticleIds)) {
                        $q->whereIn('id', $mappedArticleIds);
                    }
                    
                    // Also include articles that match carrier
                    $q->orWhere(function ($carrierQuery) use ($schedule) {
                        $carrierQuery->where('shipping_carrier_id', $schedule->carrier_id)
                          ->orWhereNull('shipping_carrier_id');
                    });
                });
                
                // #region agent log
                $articlesAfterCarrierFilter = (clone $query)->get();
                @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'D',
                    'location' => 'RobawsArticleCache.php:1202',
                    'message' => 'After carrier filter',
                    'data' => [
                        'articles_count' => $articlesAfterCarrierFilter->count(),
                        'articles_ids' => $articlesAfterCarrierFilter->pluck('id')->toArray(),
                    ],
                    'timestamp' => time() * 1000
                ]) . "\n", FILE_APPEND);
                // #endregion
            }
        }
        
        // Also check preferred_carrier_id if set
        if ($quotation->preferred_carrier_id) {
            $query->where(function ($q) use ($quotation) {
                $q->where('shipping_carrier_id', $quotation->preferred_carrier_id)
                  ->orWhereNull('shipping_carrier_id');
            });
        }

        // PHASE 4: Apply ALLOWLIST strategy if mappings exist (already checked above)
        // Note: $mappings variable is now $allMappings, but we use $mappedArticleIds for the filter
        $mappings = $allMappings;

        // #region agent log
        @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => 'RobawsArticleCache.php:1048',
            'message' => 'Article mappings check',
            'data' => [
                'quotation_id' => $quotation->id ?? null,
                'carrier_id' => $carrierId ?? null,
                'pod_port_id' => $podPortId ?? null,
                'vehicle_category' => $vehicleCategory ?? null,
                'category_group_id' => $categoryGroupId ?? null,
                'mappings_count' => $mappings->count(),
                'mapped_article_ids' => $mappings->pluck('article_id')->unique()->toArray(),
            ],
            'timestamp' => time() * 1000
        ]) . "\n", FILE_APPEND);
        // #endregion

        // If mappings exist, apply ALLOWLIST strategy
        if ($mappings->isNotEmpty()) {
            // Ensure $mappedArticleIds is populated from $mappings if not already set
            if (empty($mappedArticleIds)) {
                $mappedArticleIds = $mappings->pluck('article_id')->unique()->toArray();
            }
            
            // #region agent log
            // Check how many mapped articles pass POL/POD filter before ALLOWLIST
            $articlesBeforeAllowlist = (clone $query)->get();
            $mappedArticlesBeforeAllowlist = $articlesBeforeAllowlist->whereIn('id', $mappedArticleIds);
            @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A',
                'location' => 'RobawsArticleCache.php:1068',
                'message' => 'ALLOWLIST strategy applied',
                'data' => [
                    'quotation_id' => $quotation->id ?? null,
                    'mapped_article_ids_count' => count($mappedArticleIds),
                    'mapped_article_ids' => $mappedArticleIds,
                    'articles_before_allowlist_count' => $articlesBeforeAllowlist->count(),
                    'mapped_articles_before_allowlist_count' => $mappedArticlesBeforeAllowlist->count(),
                    'mapped_articles_before_allowlist_ids' => $mappedArticlesBeforeAllowlist->pluck('id')->toArray(),
                ],
                'timestamp' => time() * 1000
            ]) . "\n", FILE_APPEND);
            // #endregion
            
            // Apply allowlist: only mapped articles + universal articles (commodity_type NULL)
            // BUT: Also filter mapped articles by POD match to ensure they're for the correct destination
            $query->where(function ($q) use ($mappedArticleIds, $quotation, $quotationPodCode, $useIlike) {
                // #region agent log
                @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'C',
                    'location' => 'RobawsArticleCache.php:1266',
                    'message' => 'ALLOWLIST closure - mappedArticleIds check',
                    'data' => [
                        'mapped_article_ids_count' => count($mappedArticleIds),
                        'mapped_article_ids' => $mappedArticleIds,
                        'is_empty' => empty($mappedArticleIds),
                    ],
                    'timestamp' => time() * 1000
                ]) . "\n", FILE_APPEND);
                // #endregion
                
                // Mapped articles that also match POD (strict POD matching)
                // When an article is mapped to a port, its POD field is updated to match that port
                // So we still need to verify POD matches to ensure strict POD matching
                if (!empty($mappedArticleIds)) {
                    $q->where(function ($mappedQuery) use ($mappedArticleIds, $quotation, $quotationPodCode, $useIlike) {
                        $mappedQuery->whereIn('id', $mappedArticleIds);
                        
                        // If quotation has POD, ensure article POD matches (strict matching)
                        if ($quotation->pod) {
                            $podCode = $quotationPodCode ?? $this->extractPortCode($quotation->pod);
                            $mappedQuery->where(function ($podMatchQuery) use ($quotation, $podCode, $useIlike) {
                                // Article has no POD (universal)
                                $podMatchQuery->whereNull('pod');
                                
                                // Or article POD matches quotation POD (strict match)
                                if ($podCode) {
                                    if ($useIlike) {
                                        $podMatchQuery->orWhere('pod', 'ILIKE', '%(' . $podCode . ')%')
                                            ->orWhere('pod', 'ILIKE', $quotation->pod);
                                    } else {
                                        $podMatchQuery->orWhereRaw('LOWER(pod) LIKE LOWER(?)', ['%(' . $podCode . ')%'])
                                            ->orWhereRaw('LOWER(TRIM(pod)) = LOWER(TRIM(?))', [$quotation->pod]);
                                    }
                                } else {
                                    if ($useIlike) {
                                        $podMatchQuery->orWhere('pod', 'ILIKE', $quotation->pod);
                                    } else {
                                        $podMatchQuery->orWhereRaw('LOWER(TRIM(pod)) = LOWER(TRIM(?))', [$quotation->pod]);
                                    }
                                }
                            });
                        }
                    });
                }
                
                // Keep universal articles (commodity_type NULL)
                $q->orWhereNull('commodity_type');
            });
            
            // #region agent log
            $articlesAfterAllowlist = (clone $query)->get();
            @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C',
                'location' => 'RobawsArticleCache.php:1272',
                'message' => 'After ALLOWLIST applied',
                'data' => [
                    'quotation_id' => $quotation->id ?? null,
                    'articles_after_allowlist_count' => $articlesAfterAllowlist->count(),
                    'articles_after_allowlist_ids' => $articlesAfterAllowlist->pluck('id')->toArray(),
                ],
                'timestamp' => time() * 1000
            ]) . "\n", FILE_APPEND);
            // #endregion
        } else {
            // #region agent log
            @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C',
                'location' => 'RobawsArticleCache.php:1285',
                'message' => 'ALLOWLIST NOT applied - mappings empty',
                'data' => [
                    'quotation_id' => $quotation->id ?? null,
                    'mappings_count' => $mappings->count(),
                    'mappings_is_empty' => $mappings->isEmpty(),
                ],
                'timestamp' => time() * 1000
            ]) . "\n", FILE_APPEND);
            // #endregion
            
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
        
        // #region agent log
        @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'B',
            'location' => 'RobawsArticleCache.php:1081',
            'message' => 'Commodity type extraction',
            'data' => [
                'quotation_id' => $quotation->id ?? null,
                'quotation_commodity_type' => $quotation->commodity_type ?? null,
                'commodity_items_count' => $quotation->commodityItems ? $quotation->commodityItems->count() : 0,
                'commodity_items_types' => $quotation->commodityItems ? $quotation->commodityItems->pluck('commodity_type')->toArray() : [],
                'extracted_commodity_types' => $commodityTypes,
            ],
            'timestamp' => time() * 1000
        ]) . "\n", FILE_APPEND);
        // #endregion
        
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

            $placeholders = implode(', ', array_fill(0, count($commodityTypes), '?'));
            // Include articles that match the commodity type OR have NULL commodity_type (universal articles)
            // Strict filtering: only show articles that match the expanded commodity types
            $query->where(function ($q) use ($commodityTypes, $placeholders) {
                $q->whereRaw("UPPER(TRIM(commodity_type)) IN ($placeholders)", $commodityTypes)
                  ->orWhereNull('commodity_type');
            });
            
            // #region agent log
            @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'B',
                'location' => 'RobawsArticleCache.php:1129',
                'message' => 'Commodity type filter applied',
                'data' => [
                    'quotation_id' => $quotation->id ?? null,
                    'final_commodity_types' => $commodityTypes,
                ],
                'timestamp' => time() * 1000
            ]) . "\n", FILE_APPEND);
            // #endregion
        }
        // If no commodity selected, show all articles (existing behavior)

        // #region agent log
        $articleCountBeforeReturn = $query->count();
        @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'C',
            'location' => 'RobawsArticleCache.php:1136',
            'message' => 'scopeForQuotationContext exit',
            'data' => [
                'quotation_id' => $quotation->id ?? null,
                'articles_count' => $articleCountBeforeReturn,
            ],
            'timestamp' => time() * 1000
        ]) . "\n", FILE_APPEND);
        // #endregion

        // #region agent log
        $finalArticles = (clone $query)->get();
        @file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'E',
            'location' => 'RobawsArticleCache.php:1561',
            'message' => 'Final query result',
            'data' => [
                'quotation_id' => $quotation->id ?? null,
                'final_articles_count' => $finalArticles->count(),
                'final_articles_ids' => $finalArticles->pluck('id')->toArray(),
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

    /**
     * Get effective update date (override takes precedence over base)
     */
    public function getEffectiveUpdateDateAttribute()
    {
        return $this->update_date_override ?? $this->update_date;
    }

    /**
     * Get effective validity date (override takes precedence over base)
     */
    public function getEffectiveValidityDateAttribute()
    {
        return $this->validity_date_override ?? $this->validity_date;
    }

    /**
     * Get fields that have changed since last push to Robaws
     * Returns array of field keys that can be pushed
     */
    public function getChangedFieldsSinceLastPush(): array
    {
        $pushService = app(\App\Services\Robaws\RobawsArticlePushService::class);
        return $pushService->getChangedFieldsSinceLastPush($this);
    }

    /**
     * Check if article has been pushed to Robaws
     */
    public function hasBeenPushedToRobaws(): bool
    {
        return $this->last_pushed_to_robaws_at !== null;
    }

    /**
     * Check if article has local changes that haven't been pushed
     */
    public function hasUnpushedChanges(): bool
    {
        if (!$this->last_pushed_to_robaws_at) {
            // Never pushed, check if any pushable fields have values
            return !empty($this->getChangedFieldsSinceLastPush());
        }

        // Check if updated after last push
        return $this->updated_at && $this->updated_at->gt($this->last_pushed_to_robaws_at);
    }
}

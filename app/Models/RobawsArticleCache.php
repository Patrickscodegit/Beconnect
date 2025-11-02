<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

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
        'is_active',
        'requires_manual_review',
        'last_synced_at',
        'last_modified_at',
        // Article metadata from Robaws ARTICLE INFO
        'shipping_line',
        'service_type',
        'pol_terminal',
        'is_parent_item',
        // Article metadata from Robaws IMPORTANT INFO
        'article_info',
        'update_date',
        'validity_date',
        // Port information in full Robaws format: "Antwerp (ANR), Belgium"
        'pol',
        'pod',
        'commodity_type',
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
        'is_active' => 'boolean',
        'requires_manual_review' => 'boolean',
        'last_synced_at' => 'datetime',
        'last_modified_at' => 'datetime',
        // New metadata fields
        'is_parent_item' => 'boolean',
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
        return $query->where('shipping_line', 'ILIKE', '%' . $carrierCode . '%')
                     ->orWhereNull('shipping_line');
    }

    public function scopeForService(Builder $query, string $serviceType): Builder
    {
        return $query->where(function ($q) use ($serviceType) {
            $q->whereJsonContains('applicable_services', $serviceType)
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
        )->withPivot(['sort_order', 'is_required', 'is_conditional', 'conditions', 'cost_type', 'default_quantity', 'default_cost_price', 'unit_type'])
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
        )->withPivot(['sort_order', 'is_required', 'is_conditional', 'conditions', 'cost_type', 'default_quantity', 'default_cost_price', 'unit_type'])
          ->withTimestamps();
    }

    /**
     * Quotation requests this article is used in
     */
    public function quotationRequests(): BelongsToMany
    {
        return $this->belongsToMany(QuotationRequest::class, 'quotation_request_articles', 'article_cache_id', 'quotation_request_id')
            ->withPivot(['parent_article_id', 'item_type', 'quantity', 'unit_price', 'selling_price', 'subtotal', 'currency', 'formula_inputs', 'calculated_price', 'notes'])
            ->withTimestamps();
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
        return $query->where('service_type', $serviceType);
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
     * Scope for filtering articles based on complete quotation context
     * This is the main method for smart article selection
     */
    public function scopeForQuotationContext(Builder $query, \App\Models\QuotationRequest $quotation): Builder
    {
        // Only show parent items that are active and valid
        $query->where('is_parent_item', true)
              ->where('is_active', true)
              ->validAsOf(now());

        // Apply POL/POD filtering using case-insensitive ILIKE matching
        // Quotation may have "Antwerp" while article has "Antwerp (ANR), Belgium"
        if ($quotation->pol) {
            $query->where(function ($q) use ($quotation) {
                $q->where('pol', 'ILIKE', '%' . $quotation->pol . '%')
                  ->orWhereNull('pol'); // Include articles without POL restriction
            });
        }
        
        if ($quotation->pod) {
            $query->where(function ($q) use ($quotation) {
                $q->where('pod', 'ILIKE', '%' . $quotation->pod . '%')
                  ->orWhereNull('pod'); // Include articles without POD restriction
            });
        }

        // Apply service type filter if available
        if ($quotation->service_type) {
            $query->where(function ($q) use ($quotation) {
                $q->where('service_type', $quotation->service_type)
                  ->orWhereNull('service_type'); // Include articles without service type restriction
            });
        }

        // Apply shipping line filter if schedule is selected (case-insensitive)
        if ($quotation->selected_schedule_id && $quotation->selectedSchedule) {
            $schedule = $quotation->selectedSchedule;
            if ($schedule->carrier) {
                $query->where(function ($q) use ($schedule) {
                    $q->where('shipping_line', 'ILIKE', '%' . $schedule->carrier->name . '%')
                      ->orWhereNull('shipping_line'); // Include articles without carrier restriction
                });
            }
        }

        // Apply commodity type filter (HYBRID: strict when selected, flexible when not)
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
        
        // STRICT filtering when commodity is selected
        if (!empty($commodityTypes)) {
            $commodityTypes = array_filter(array_unique($commodityTypes));
            $query->whereIn('commodity_type', $commodityTypes);
        }
        // If no commodity selected, show all articles (existing behavior)

        return $query;
    }

    // extractPortCodeFromString() removed - now using direct LIKE matching in scopeForQuotationContext()

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
}

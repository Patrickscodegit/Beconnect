<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class QuotationRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        // 'request_number', // PROTECTED - Only set by creating event to ensure uniqueness
        'source',
        'requester_type',
        // Client fields (Robaws Client API)
        'client_name',
        'client_email',
        'client_tel',
        'robaws_client_id',
        // Contact fields (Robaws Contact API)
        'contact_name',
        'contact_email',
        'contact_phone',
        'contact_company',
        'contact_function',
        'customer_reference',
        'simple_service_type', // Customer-facing simplified service type
        'service_type',
        'trade_direction',
        'routing',
        'por',
        'pol',
        'pod',
        'fdest',
        'cargo_details',
        'cargo_description',
        'commodity_type', // Legacy field (for backward compatibility)
        'total_commodity_items', // New multi-commodity system
        'robaws_cargo_field', // Generated CARGO field for Robaws
        'robaws_dim_field', // Generated DIM_BEF_DELIVERY field for Robaws
        'special_requirements',
        'selected_schedule_id',
        'preferred_carrier',
        'preferred_departure_date',
        'robaws_offer_id',
        'robaws_offer_number',
        'robaws_sync_status',
        'robaws_synced_at',
        'intake_id',
        'status',
        'quoted_at',
        'expires_at',
        // Pricing fields
        'customer_role', // WHO the customer is (FORWARDER, CONSIGNEE, etc.)
        'pricing_tier_id', // WHAT pricing they get (Tier A/B/C with margins)
        'customer_type',
        'subtotal',
        'discount_amount',
        'discount_percentage',
        'total_excl_vat',
        'vat_amount',
        'vat_rate',
        'total_incl_vat',
        'pricing_currency',
        // Template fields
        'intro_template_id',
        'end_template_id',
        'intro_text',
        'end_text',
        'template_variables',
        // Assignment
        'assigned_to',
        'created_by',
    ];

    protected $casts = [
        'routing' => 'array',
        'cargo_details' => 'array',
        'template_variables' => 'array',
        'preferred_departure_date' => 'date',
        'robaws_synced_at' => 'datetime',
        'quoted_at' => 'datetime',
        'expires_at' => 'datetime',
        'deleted_at' => 'datetime',
        // Pricing casts
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'total_excl_vat' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'total_incl_vat' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($quotationRequest) {
            try {
                // Log what's being created
                \Log::info('QuotationRequest::creating - Model data', [
                    'attributes' => $quotationRequest->getAttributes(),
                    'request_number' => $quotationRequest->request_number ?? 'NOT_SET',
                    'status' => $quotationRequest->status ?? 'NOT_SET',
                    'source' => $quotationRequest->source ?? 'NOT_SET',
                    'cargo_details' => $quotationRequest->cargo_details ?? 'NOT_SET',
                    'intro_template_id' => $quotationRequest->intro_template_id ?? 'NOT_SET',
                    'end_template_id' => $quotationRequest->end_template_id ?? 'NOT_SET',
                ]);
                
                // Always ensure we have a unique request number
                if (empty($quotationRequest->request_number)) {
                    $quotationRequest->request_number = self::generateRequestNumber();
                    \Log::info('Generated request number: ' . $quotationRequest->request_number);
                } else {
                    // Even if a request number is provided, ensure it's unique (including soft-deleted)
                    $originalNumber = $quotationRequest->request_number;
                    while (self::withTrashed()->where('request_number', $quotationRequest->request_number)->where('id', '!=', $quotationRequest->id ?? 0)->exists()) {
                        $quotationRequest->request_number = self::generateRequestNumber();
                    }
                    if ($originalNumber !== $quotationRequest->request_number) {
                        \Log::info('Request number conflict resolved', [
                            'original' => $originalNumber,
                            'new' => $quotationRequest->request_number
                        ]);
                    }
                }
            } catch (\Exception $e) {
                \Log::error('QuotationRequest::creating - Error', [
                    'message' => $e->getMessage(),
                    'attributes' => $quotationRequest->getAttributes(),
                ]);
                throw $e;
            }
        });

        static::updating(function ($quotationRequest) {
            // Ensure request number remains unique during updates (including soft-deleted)
            if (!empty($quotationRequest->request_number)) {
                $originalNumber = $quotationRequest->getOriginal('request_number');
                if ($originalNumber !== $quotationRequest->request_number) {
                    // Request number was changed, ensure it's unique
                    while (self::withTrashed()->where('request_number', $quotationRequest->request_number)->where('id', '!=', $quotationRequest->id)->exists()) {
                        $quotationRequest->request_number = self::generateRequestNumber();
                    }
                }
            }
        });
    }

    /**
     * Generate unique request number
     */
    public static function generateRequestNumber(): string
    {
        $prefix = 'QR-' . date('Y') . '-';
        
        // Use a loop to ensure we get a truly unique number
        $attempts = 0;
        $maxAttempts = 100;
        
        do {
            // Get the highest existing number for this year (including soft-deleted)
            $lastRequest = self::withTrashed()
                ->where('request_number', 'like', $prefix . '%')
                ->orderBy('request_number', 'desc')
                ->first();

            if ($lastRequest) {
                $lastNumber = (int) Str::afterLast($lastRequest->request_number, '-');
                $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
            } else {
                $newNumber = '0001';
            }

            $requestNumber = $prefix . $newNumber;
            
            // Check if this number already exists (including soft-deleted)
            $exists = self::withTrashed()->where('request_number', $requestNumber)->exists();
            
            if (!$exists) {
                return $requestNumber;
            }
            
            $attempts++;
            
        } while ($attempts < $maxAttempts);
        
        // Fallback: use timestamp if we somehow can't find a unique number
        return $prefix . date('mdHis');
    }

    /**
     * Relationships
     */
    public function files(): HasMany
    {
        return $this->hasMany(QuotationRequestFile::class);
    }

    public function commodityItems(): HasMany
    {
        return $this->hasMany(QuotationCommodityItem::class)->orderBy('line_number');
    }

    /**
     * Get the total number of commodity items
     */
    public function getTotalItemsAttribute(): int
    {
        return $this->commodityItems()->count();
    }

    /**
     * Check if this quotation uses the new multi-commodity system
     */
    public function hasMultiCommodityItems(): bool
    {
        return $this->commodityItems()->exists();
    }

    public function selectedSchedule(): BelongsTo
    {
        return $this->belongsTo(ShippingSchedule::class, 'selected_schedule_id');
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(RobawsArticleCache::class, 'quotation_request_articles', 'quotation_request_id', 'article_cache_id')
            ->withPivot(['parent_article_id', 'item_type', 'quantity', 'unit_price', 'selling_price', 'subtotal', 'currency', 'formula_inputs', 'calculated_price', 'notes'])
            ->withTimestamps()
            ->orderBy('quotation_request_articles.id'); // Preserve insertion order
    }

    public function introTemplate(): BelongsTo
    {
        return $this->belongsTo(OfferTemplate::class, 'intro_template_id');
    }

    public function endTemplate(): BelongsTo
    {
        return $this->belongsTo(OfferTemplate::class, 'end_template_id');
    }

    public function pricingTier(): BelongsTo
    {
        return $this->belongsTo(PricingTier::class, 'pricing_tier_id');
    }

    /**
     * Accessors
     */
    
    /**
     * Get the pricing margin percentage for this quotation
     * Uses pricing tier if set, otherwise falls back to customer role
     */
    public function getPricingMarginPercentageAttribute(): float
    {
        // Priority 1: Use pricing_tier if set
        try {
            if ($this->pricing_tier_id && $this->pricingTier) {
                return $this->pricingTier->margin_percentage;
            }
        } catch (\Exception $e) {
            // Column might not exist yet if migrations haven't run
            \Log::warning('pricing_tier_id column not found, falling back to customer_role', [
                'error' => $e->getMessage()
            ]);
        }
        
        // Priority 2: Fallback to customer_role (for backward compatibility)
        if ($this->customer_role) {
            return config("quotation.customer_role_margins.{$this->customer_role}", 15.00);
        }
        
        // Default: 15% markup
        return 15.00;
    }
    
    /**
     * Get the pricing margin multiplier
     * Example: 15% = 1.15, -5% = 0.95
     */
    public function getPricingMarginMultiplierAttribute(): float
    {
        return 1 + ($this->pricing_margin_percentage / 100);
    }
    
    /**
     * Get pricing tier display name
     */
    public function getPricingTierDisplayAttribute(): string
    {
        try {
            if ($this->pricing_tier_id && $this->pricingTier) {
                return $this->pricingTier->name . ' (' . $this->pricingTier->formatted_margin . ')';
            }
        } catch (\Exception $e) {
            // Column might not exist yet
        }
        
        return 'Standard (15% markup)';
    }

    /**
     * Accessors (existing)
     */
    public function getRouteDisplayAttribute(): string
    {
        $routing = $this->routing ?? [];
        $pol = $routing['pol'] ?? 'N/A';
        $pod = $routing['pod'] ?? 'N/A';
        return "{$pol} → {$pod}";
    }

    public function getCargoSummaryAttribute(): string
    {
        $cargo = $this->cargo_details ?? [];
        $type = $cargo['type'] ?? 'cargo';
        $quantity = $cargo['quantity'] ?? 1;
        return "{$quantity}x {$type}";
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function getFormattedSubtotalAttribute(): string
    {
        if (!$this->subtotal) {
            return 'N/A';
        }
        return ($this->pricing_currency ?? 'EUR') . ' ' . number_format($this->subtotal, 2);
    }

    public function getFormattedTotalAttribute(): string
    {
        if (!$this->total_incl_vat) {
            return 'N/A';
        }
        return ($this->pricing_currency ?? 'EUR') . ' ' . number_format($this->total_incl_vat, 2);
    }

    /**
     * Business Logic Methods
     */
    
    /**
     * Calculate and update all pricing totals
     * Called automatically when articles are added/removed
     */
    public function calculateTotals(): void
    {
        // Sum all article subtotals
        $articleSubtotals = QuotationRequestArticle::where('quotation_request_id', $this->id)
            ->sum('subtotal');
        
        $this->subtotal = $articleSubtotals;
        
        // Apply discount
        if ($this->discount_percentage > 0) {
            $this->discount_amount = ($this->subtotal * $this->discount_percentage) / 100;
        }
        
        $this->total_excl_vat = $this->subtotal - ($this->discount_amount ?? 0);
        
        // Calculate VAT
        $vatRate = $this->vat_rate ?? config('quotation.vat_rate', 21.00);
        $this->vat_amount = ($this->total_excl_vat * $vatRate) / 100;
        
        $this->total_incl_vat = $this->total_excl_vat + $this->vat_amount;
        
        // Save without triggering events (to avoid recursion)
        $this->saveQuietly();
    }

    /**
     * Add an article to the quotation
     *
     * @param RobawsArticleCache $article
     * @param int $quantity
     * @param array $formulaInputs Optional formula inputs for CONSOL pricing
     * @return QuotationRequestArticle
     */
    public function addArticle(RobawsArticleCache $article, int $quantity = 1, array $formulaInputs = []): QuotationRequestArticle
    {
        // Use pricing tier if available, otherwise fall back to customer role
        $sellingPrice = null;
        
        try {
            if ($this->pricing_tier_id && $this->pricingTier) {
                $sellingPrice = $article->getPriceForTier($this->pricingTier, $formulaInputs ?: null);
            }
        } catch (\Exception $e) {
            // Column might not exist yet, fall back to role
        }
        
        if ($sellingPrice === null) {
            $role = $this->customer_role ?? 'default';
            $sellingPrice = $article->getPriceForRole($role, $formulaInputs ?: null);
        }
        
        $itemType = $article->is_parent_article ? 'parent' : 'standalone';
        
        return QuotationRequestArticle::create([
            'quotation_request_id' => $this->id,
            'article_cache_id' => $article->id,
            'item_type' => $itemType,
            'quantity' => $quantity,
            'unit_price' => $article->unit_price,
            'selling_price' => $sellingPrice,
            'currency' => $article->currency,
            'formula_inputs' => $formulaInputs ?: null,
        ]);
        // Child articles and totals are calculated automatically in QuotationRequestArticle::boot()
    }

    /**
     * Render intro text with variables
     */
    public function renderIntroText(): ?string
    {
        if ($this->introTemplate && $this->template_variables) {
            return $this->introTemplate->render($this->template_variables);
        }
        
        return $this->intro_text;
    }

    /**
     * Render end text with variables
     */
    public function renderEndText(): ?string
    {
        if ($this->endTemplate && $this->template_variables) {
            return $this->endTemplate->render($this->template_variables);
        }
        
        return $this->end_text;
    }

    /**
     * Get all parent articles (excluding children)
     */
    public function getParentArticles()
    {
        return QuotationRequestArticle::where('quotation_request_id', $this->id)
            ->whereIn('item_type', ['parent', 'standalone'])
            ->with('articleCache')
            ->get();
    }

    /**
     * Get total article count (excluding child articles for display)
     */
    public function getArticleCount(): int
    {
        return QuotationRequestArticle::where('quotation_request_id', $this->id)
            ->whereIn('item_type', ['parent', 'standalone'])
            ->count();
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeQuoted($query)
    {
        return $query->where('status', 'quoted');
    }

    public function scopeFromProspects($query)
    {
        return $query->where('source', 'prospect');
    }

    public function scopeFromCustomers($query)
    {
        return $query->where('source', 'customer');
    }

    public function scopeFromIntakes($query)
    {
        return $query->where('source', 'intake');
    }
}

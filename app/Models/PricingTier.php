<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class PricingTier extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'margin_percentage',
        'color',
        'icon',
        'sort_order',
        'is_active',
    ];
    
    protected $casts = [
        'margin_percentage' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
    
    /**
     * Boot method to handle cache invalidation
     */
    protected static function booted()
    {
        // Clear cache when tiers are created, updated, or deleted
        static::saved(function () {
            Cache::forget('pricing_tiers_active');
            Cache::forget('pricing_tiers_all');
        });
        
        static::deleted(function () {
            Cache::forget('pricing_tiers_active');
            Cache::forget('pricing_tiers_all');
        });
    }
    
    /**
     * Get margin as decimal (e.g., 0.10 for 10%, -0.05 for -5%)
     */
    public function getMarginDecimalAttribute(): float
    {
        return $this->margin_percentage / 100;
    }
    
    /**
     * Get margin multiplier (e.g., 1.10 for 10%, 0.95 for -5%)
     */
    public function getMarginMultiplierAttribute(): float
    {
        return 1 + ($this->margin_percentage / 100);
    }
    
    /**
     * Check if this tier applies a discount (negative margin)
     */
    public function getIsDiscountAttribute(): bool
    {
        return $this->margin_percentage < 0;
    }
    
    /**
     * Check if this tier applies a markup (positive margin)
     */
    public function getIsMarkupAttribute(): bool
    {
        return $this->margin_percentage > 0;
    }
    
    /**
     * Get formatted margin display
     */
    public function getFormattedMarginAttribute(): string
    {
        $prefix = $this->margin_percentage > 0 ? '+' : '';
        $suffix = $this->is_discount ? ' DISCOUNT' : ($this->is_markup ? ' MARKUP' : ' PASS-THROUGH');
        
        return $prefix . number_format($this->margin_percentage, 2) . '%' . $suffix;
    }
    
    /**
     * Get display label for select fields
     */
    public function getSelectLabelAttribute(): string
    {
        return sprintf(
            '%s Tier %s - %s (%s)',
            $this->icon ?? '',
            $this->code,
            $this->name,
            $this->formatted_margin
        );
    }
    
    /**
     * Calculate selling price from base price
     * 
     * @param float $basePrice Robaws article base price
     * @return float Selling price with margin applied
     */
    public function calculateSellingPrice(float $basePrice): float
    {
        return round($basePrice * $this->margin_multiplier, 2);
    }
    
    /**
     * Relationships
     */
    public function quotationRequests(): HasMany
    {
        return $this->hasMany(QuotationRequest::class);
    }
    
    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                    ->orderBy('sort_order');
    }
    
    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }
    
    /**
     * Static helper methods
     */
    
    /**
     * Get all active tiers (cached)
     */
    public static function getActiveTiers(): \Illuminate\Support\Collection
    {
        return Cache::remember('pricing_tiers_active', 3600, function () {
            return self::active()->get();
        });
    }
    
    /**
     * Get tier by code (cached)
     */
    public static function getByCode(string $code): ?self
    {
        return Cache::remember("pricing_tier_code_{$code}", 3600, function () use ($code) {
            return self::where('code', $code)->where('is_active', true)->first();
        });
    }
    
    /**
     * Get tier options for select fields
     */
    public static function getSelectOptions(): array
    {
        return self::getActiveTiers()
            ->mapWithKeys(function ($tier) {
                return [$tier->id => $tier->select_label];
            })
            ->toArray();
    }
}


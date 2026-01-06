<?php

namespace App\Models;

use App\Models\Concerns\HasMultiScopeMatches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarrierArticleMapping extends Model
{
    use HasFactory, HasMultiScopeMatches;

    protected $fillable = [
        'carrier_id',
        'article_id',
        'name',
        'port_ids',
        'port_group_ids',
        'vehicle_categories',
        'category_group_ids',
        'vessel_names',
        'vessel_classes',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'port_ids' => 'array',
        'port_group_ids' => 'array',
        'vehicle_categories' => 'array',
        'category_group_ids' => 'array',
        'vessel_names' => 'array',
        'vessel_classes' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Normalize empty arrays to NULL before saving
     */
    protected static function booted(): void
    {
        static::saving(function ($model) {
            foreach (['port_ids', 'port_group_ids', 'vehicle_categories', 'category_group_ids', 'vessel_names', 'vessel_classes'] as $field) {
                if (isset($model->attributes[$field])) {
                    $value = $model->attributes[$field];
                    // If it's a JSON string (after cast encoding), decode it first
                    if (is_string($value)) {
                        $decoded = json_decode($value, true);
                        if (empty($decoded)) {
                            $model->attributes[$field] = null;
                        }
                    } elseif (empty($value)) {
                        $model->attributes[$field] = null;
                    }
                }
            }
        });
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(RobawsArticleCache::class);
    }

    public function purchaseTariffs(): HasMany
    {
        return $this->hasMany(CarrierPurchaseTariff::class)
            ->orderBy('sort_order', 'asc')
            ->orderBy('effective_from', 'asc');
    }

    public function activePurchaseTariff(): ?CarrierPurchaseTariff
    {
        // If relationship is eager-loaded, filter in memory to avoid N+1 queries
        // and ensure we use the same tariffs that were eager-loaded with surcharges
        if ($this->relationLoaded('purchaseTariffs')) {
            $now = \Carbon\Carbon::now();
            $filtered = $this->purchaseTariffs
                ->filter(function ($tariff) use ($now) {
                    // Apply active() scope logic in memory
                    if (!$tariff->is_active) {
                        return false;
                    }
                    if ($tariff->effective_from && $tariff->effective_from > $now) {
                        return false;
                    }
                    if ($tariff->effective_to && $tariff->effective_to < $now) {
                        return false;
                    }
                    return true;
                })
                ->sortBy([
                    ['effective_from', 'desc'],
                    ['sort_order', 'asc'],
                ]);
            
            return $filtered->first();
        }
        
        // Fallback to query if not eager-loaded
        return $this->purchaseTariffs()->active()->first();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}


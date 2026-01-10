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
            
            // Validate that article's carrier matches mapping's carrier
            if ($model->article_id && $model->carrier_id) {
                $article = RobawsArticleCache::find($model->article_id);
                if ($article && $article->shipping_carrier_id !== null) {
                    // Article has a specific carrier - it must match the mapping's carrier
                    if ($article->shipping_carrier_id != $model->carrier_id) {
                        $articleCode = $article->article_code ?? 'N/A';
                        $articleName = $article->article_name ?? 'Unknown';
                        $articleCarrier = ShippingCarrier::find($article->shipping_carrier_id);
                        $mappingCarrier = ShippingCarrier::find($model->carrier_id);
                        
                        $mappingCarrierName = $mappingCarrier?->name ?? $model->carrier_id;
                        $articleCarrierName = $articleCarrier?->name ?? $article->shipping_carrier_id;
                        
                        throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                            "Cannot map article '{$articleCode}' ({$articleName}) to carrier '{$mappingCarrierName}'. " .
                            "Article belongs to carrier '{$articleCarrierName}'. " .
                            "Universal articles (no carrier) can be mapped to any carrier."
                        );
                    }
                }
                // If article has null shipping_carrier_id, it's universal and can be mapped to any carrier - no validation needed
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
        // Get the most recent active tariff (effective_from DESC, then sort_order ASC)
        return $this->purchaseTariffs()
            ->active()
            ->orderBy('effective_from', 'desc')
            ->orderBy('sort_order', 'asc')
            ->first();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}


<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarrierSurchargeRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'carrier_id',
        'port_id',
        'vehicle_category',
        'category_group_id',
        'vessel_name',
        'vessel_class',
        'event_code',
        'name',
        'calc_mode',
        'params',
        'priority',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected $casts = [
        'params' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class);
    }

    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class);
    }

    public function categoryGroup(): BelongsTo
    {
        return $this->belongsTo(CarrierCategoryGroup::class);
    }

    public function articleMaps(): HasMany
    {
        return $this->hasMany(CarrierSurchargeArticleMap::class, 'event_code', 'event_code');
    }

    /**
     * Get exclusive group if set (prevents double charging)
     */
    public function getExclusiveGroup(): ?string
    {
        return $this->params['exclusive_group'] ?? null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('effective_from')
                  ->orWhere('effective_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', now());
            });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierSurchargeArticleMap extends Model
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
        'article_id',
        'qty_mode',
        'params',
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

    public function article(): BelongsTo
    {
        return $this->belongsTo(RobawsArticleCache::class);
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

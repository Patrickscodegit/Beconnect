<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierPurchaseTariff extends Model
{
    use HasFactory;

    protected $fillable = [
        'carrier_article_mapping_id',
        'effective_from',
        'effective_to',
        'is_active',
        'sort_order',
        'currency',
        'base_freight_amount',
        'base_freight_unit',
        'source',
        'notes',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
        'base_freight_amount' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function carrierArticleMapping(): BelongsTo
    {
        return $this->belongsTo(CarrierArticleMapping::class);
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
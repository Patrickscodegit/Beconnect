<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarrierCategoryGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'carrier_id',
        'code',
        'display_name',
        'aliases',
        'priority',
        'sort_order',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected $casts = [
        'aliases' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(CarrierCategoryGroupMember::class, 'carrier_category_group_id');
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(CarrierCategoryGroupMember::class, 'carrier_category_group_id')->where('is_active', true);
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

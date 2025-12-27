<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierCategoryGroupMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'carrier_category_group_id',
        'vehicle_category',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function categoryGroup(): BelongsTo
    {
        return $this->belongsTo(CarrierCategoryGroup::class, 'carrier_category_group_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

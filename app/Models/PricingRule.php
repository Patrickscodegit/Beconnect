<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'pricing_profile_id',
        'vehicle_category',
        'unit_basis',
        'margin_type',
        'margin_value',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'margin_value' => 'decimal:2',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PricingProfile::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
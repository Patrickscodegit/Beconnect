<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierTransformRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'carrier_id',
        'port_id',
        'vehicle_category',
        'category_group_id',
        'vessel_name',
        'vessel_class',
        'transform_code',
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

    /**
     * Check if width exceeds trigger threshold
     */
    public function triggers(float $widthCm): bool
    {
        if ($this->transform_code !== 'OVERWIDTH_LM_RECALC') {
            return false;
        }

        $triggerWidth = $this->params['trigger_width_gt_cm'] ?? 250;
        return $widthCm > $triggerWidth;
    }

    /**
     * Get divisor in cm (usually 250 for 2.5m)
     */
    public function getDivisorCm(): float
    {
        return $this->params['divisor_cm'] ?? 250.0;
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

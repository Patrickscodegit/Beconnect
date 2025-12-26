<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierClassificationBand extends Model
{
    use HasFactory;

    protected $fillable = [
        'carrier_id',
        'port_id',
        'vessel_name',
        'vessel_class',
        'outcome_vehicle_category',
        'min_cbm',
        'max_cbm',
        'max_height_cm',
        'rule_logic',
        'priority',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected $casts = [
        'min_cbm' => 'decimal:4',
        'max_cbm' => 'decimal:4',
        'max_height_cm' => 'decimal:2',
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

    /**
     * Check if cargo matches this classification band
     */
    public function matches(float $cbm, ?float $heightCm = null): bool
    {
        $criteria = [];

        if ($this->min_cbm !== null && $cbm >= $this->min_cbm) {
            $criteria[] = true;
        } elseif ($this->min_cbm !== null) {
            $criteria[] = false;
        }

        if ($this->max_cbm !== null && $cbm <= $this->max_cbm) {
            $criteria[] = true;
        } elseif ($this->max_cbm !== null) {
            $criteria[] = false;
        }

        if ($this->max_height_cm !== null && $heightCm !== null && $heightCm <= $this->max_height_cm) {
            $criteria[] = true;
        } elseif ($this->max_height_cm !== null && $heightCm !== null) {
            $criteria[] = false;
        }

        if (empty($criteria)) {
            return false;
        }

        if ($this->rule_logic === 'OR') {
            return in_array(true, $criteria, true);
        } else { // AND
            return !in_array(false, $criteria, true);
        }
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

<?php

namespace App\Models;

use App\Models\Concerns\HasMultiScopeMatches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierClassificationBand extends Model
{
    use HasFactory, HasMultiScopeMatches;

    protected $fillable = [
        'carrier_id',
        'port_id',
        'port_ids',
        'vessel_name',
        'vessel_names',
        'vessel_class',
        'vessel_classes',
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
        'port_ids' => 'array',
        'vessel_names' => 'array',
        'vessel_classes' => 'array',
        'min_cbm' => 'decimal:4',
        'max_cbm' => 'decimal:4',
        'max_height_cm' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Normalize empty arrays to NULL before saving
     */
    protected static function booted(): void
    {
        static::saving(function ($model) {
            foreach (['port_ids', 'vessel_names', 'vessel_classes'] as $field) {
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

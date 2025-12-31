<?php

namespace App\Models;

use App\Models\Concerns\HasMultiScopeMatches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierTransformRule extends Model
{
    use HasFactory, HasMultiScopeMatches;

    protected $fillable = [
        'carrier_id',
        'port_id',
        'port_ids',
        'port_group_ids',
        'vehicle_category',
        'vehicle_categories',
        'category_group_id',
        'category_group_ids',
        'vessel_name',
        'vessel_names',
        'vessel_class',
        'vessel_classes',
        'transform_code',
        'params',
        'priority',
        'sort_order',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected $casts = [
        'port_ids' => 'array',
        'port_group_ids' => 'array',
        'vehicle_categories' => 'array',
        'category_group_ids' => 'array',
        'vessel_names' => 'array',
        'vessel_classes' => 'array',
        'params' => 'array',
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
            foreach (['port_ids', 'port_group_ids', 'vehicle_categories', 'vessel_names', 'vessel_classes', 'category_group_ids'] as $field) {
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
        // Use > (not >=) to match user requirement: "> 260 cm" triggers the transform
        // Widths exactly equal to trigger should use the minimum width calculation
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

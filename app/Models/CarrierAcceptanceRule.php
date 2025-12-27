<?php

namespace App\Models;

use App\Models\Concerns\HasMultiScopeMatches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierAcceptanceRule extends Model
{
    use HasFactory, HasMultiScopeMatches;

    protected $fillable = [
        'carrier_id',
        'port_id',
        'port_ids',
        'vehicle_category',
        'vehicle_categories',
        'category_group_id',
        'vessel_name',
        'vessel_names',
        'vessel_class',
        'vessel_classes',
        'max_length_cm',
        'max_width_cm',
        'max_height_cm',
        'max_cbm',
        'max_weight_kg',
        'must_be_empty',
        'must_be_self_propelled',
        'allow_accessories',
        'complete_vehicles_only',
        'allows_stacked',
        'allows_piggy_back',
        'soft_max_height_cm',
        'soft_height_requires_approval',
        'soft_max_weight_kg',
        'soft_weight_requires_approval',
        'is_free_out',
        'requires_waiver',
        'waiver_provided_by_carrier',
        'notes',
        'priority',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected $casts = [
        'port_ids' => 'array',
        'vehicle_categories' => 'array',
        'vessel_names' => 'array',
        'vessel_classes' => 'array',
        'max_length_cm' => 'decimal:2',
        'max_width_cm' => 'decimal:2',
        'max_height_cm' => 'decimal:2',
        'max_cbm' => 'decimal:4',
        'max_weight_kg' => 'decimal:2',
        'soft_max_height_cm' => 'decimal:2',
        'soft_max_weight_kg' => 'decimal:2',
        'must_be_empty' => 'boolean',
        'must_be_self_propelled' => 'boolean',
        'complete_vehicles_only' => 'boolean',
        'allows_stacked' => 'boolean',
        'allows_piggy_back' => 'boolean',
        'soft_height_requires_approval' => 'boolean',
        'soft_weight_requires_approval' => 'boolean',
        'is_free_out' => 'boolean',
        'requires_waiver' => 'boolean',
        'waiver_provided_by_carrier' => 'boolean',
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
            foreach (['port_ids', 'vehicle_categories', 'vessel_names', 'vessel_classes'] as $field) {
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

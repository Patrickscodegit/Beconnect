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
        'update_date',
        'validity_date',
        'is_active',
        'sort_order',
        'currency',
        'base_freight_amount',
        'base_freight_unit',
        'baf_amount',
        'baf_unit',
        'ets_amount',
        'ets_unit',
        'port_additional_amount',
        'port_additional_unit',
        'admin_fxe_amount',
        'admin_fxe_unit',
        'thc_amount',
        'thc_unit',
        'measurement_costs_amount',
        'measurement_costs_unit',
        'congestion_surcharge_amount',
        'congestion_surcharge_unit',
        'iccm_amount',
        'iccm_unit',
        'source',
        'notes',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'update_date' => 'date',
        'validity_date' => 'date',
        'is_active' => 'boolean',
        'base_freight_amount' => 'decimal:2',
        'baf_amount' => 'decimal:2',
        'ets_amount' => 'decimal:2',
        'port_additional_amount' => 'decimal:2',
        'admin_fxe_amount' => 'decimal:2',
        'thc_amount' => 'decimal:2',
        'measurement_costs_amount' => 'decimal:2',
        'congestion_surcharge_amount' => 'decimal:2',
        'iccm_amount' => 'decimal:2',
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

    /**
     * Sum all surcharge amounts (treat null as 0)
     * Note: Units do NOT change the sum here; we store values per unit basis.
     * LM-based tariffs will be multiplied later in pricing pipeline.
     */
    public function getSurchargesTotalAttribute(): float
    {
        $total = 0.0;
        if ($this->baf_amount) $total += (float) $this->baf_amount;
        if ($this->ets_amount) $total += (float) $this->ets_amount;
        if ($this->port_additional_amount) $total += (float) $this->port_additional_amount;
        if ($this->admin_fxe_amount) $total += (float) $this->admin_fxe_amount;
        if ($this->thc_amount) $total += (float) $this->thc_amount;
        if ($this->measurement_costs_amount) $total += (float) $this->measurement_costs_amount;
        if ($this->congestion_surcharge_amount) $total += (float) $this->congestion_surcharge_amount;
        if ($this->iccm_amount) $total += (float) $this->iccm_amount;
        return round($total, 2);
    }

    /**
     * Total purchase cost = base freight + surcharges
     */
    public function getTotalPurchaseCostAttribute(): float
    {
        return round((float) $this->base_freight_amount + $this->surcharges_total, 2);
    }

    /**
     * Check if any surcharge is set (non-null and > 0)
     */
    public function hasSurcharges(): bool
    {
        return ($this->baf_amount && $this->baf_amount > 0)
            || ($this->ets_amount && $this->ets_amount > 0)
            || ($this->port_additional_amount && $this->port_additional_amount > 0)
            || ($this->admin_fxe_amount && $this->admin_fxe_amount > 0)
            || ($this->thc_amount && $this->thc_amount > 0)
            || ($this->measurement_costs_amount && $this->measurement_costs_amount > 0)
            || ($this->congestion_surcharge_amount && $this->congestion_surcharge_amount > 0)
            || ($this->iccm_amount && $this->iccm_amount > 0);
    }
}
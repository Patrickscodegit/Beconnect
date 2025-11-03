<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationCommodityItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_request_id',
        'line_number',
        'commodity_type',
        'category',
        'make',
        'type_model',
        'fuel_type',
        'condition',
        'year',
        'wheelbase_cm',
        'quantity',
        'length_cm',
        'width_cm',
        'height_cm',
        'cbm',
        'weight_kg',
        'bruto_weight_kg',
        'netto_weight_kg',
        'has_parts',
        'parts_description',
        'has_trailer',
        'has_wooden_cradle',
        'has_iron_cradle',
        'is_forkliftable',
        'is_hazardous',
        'is_unpacked',
        'is_ispm15',
        'unit_price',
        'line_total',
        'extra_info',
        'attachments',
        'input_unit_system',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'line_number' => 'integer',
        'year' => 'integer',
        'wheelbase_cm' => 'decimal:2',
        'length_cm' => 'decimal:2',
        'width_cm' => 'decimal:2',
        'height_cm' => 'decimal:2',
        'cbm' => 'decimal:4',
        'weight_kg' => 'decimal:2',
        'bruto_weight_kg' => 'decimal:2',
        'netto_weight_kg' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'has_parts' => 'boolean',
        'has_trailer' => 'boolean',
        'has_wooden_cradle' => 'boolean',
        'has_iron_cradle' => 'boolean',
        'is_forkliftable' => 'boolean',
        'is_hazardous' => 'boolean',
        'is_unpacked' => 'boolean',
        'is_ispm15' => 'boolean',
        'attachments' => 'array',
    ];

    /**
     * Get the quotation request that owns this commodity item.
     */
    public function quotationRequest(): BelongsTo
    {
        return $this->belongsTo(QuotationRequest::class);
    }

    /**
     * Calculate CBM from dimensions (L × W × H) / 1,000,000
     */
    public function calculateCbm(): float
    {
        if ($this->length_cm && $this->width_cm && $this->height_cm) {
            return ($this->length_cm * $this->width_cm * $this->height_cm) / 1000000;
        }
        return 0;
    }

    /**
     * Calculate line total (unit_price × quantity)
     */
    public function calculateLineTotal(): float
    {
        return ($this->unit_price ?? 0) * $this->quantity;
    }

    /**
     * Get formatted dimensions string
     */
    public function getDimensionsAttribute(): string
    {
        if ($this->length_cm && $this->width_cm && $this->height_cm) {
            return "{$this->length_cm} × {$this->width_cm} × {$this->height_cm} cm";
        }
        return '';
    }

    /**
     * Get formatted commodity description for display
     */
    public function getDescriptionAttribute(): string
    {
        $desc = ucfirst($this->commodity_type);
        
        if ($this->category) {
            $desc .= " - " . ucwords(str_replace('_', ' ', $this->category));
        }
        
        if ($this->make || $this->type_model) {
            $desc .= " ({$this->make} {$this->type_model})";
        }
        
        return $desc;
    }

    /**
     * Boot method to auto-calculate CBM before saving
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($item) {
            // Auto-calculate CBM if dimensions are present
            if ($item->length_cm && $item->width_cm && $item->height_cm) {
                $item->cbm = $item->calculateCbm();
            }

            // Auto-calculate line total if unit price is set
            if ($item->unit_price) {
                $item->line_total = $item->calculateLineTotal();
            }
        });
    }
}

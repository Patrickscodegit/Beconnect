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
        'lm',
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
        'lm' => 'decimal:4',
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
     * Calculate LM (Linear Meter) from dimensions: (length_m × width_m) / 2.5
     * Converts cm to meters: (length_cm / 100 × max(width_cm, 250) / 100) / 2.5
     * Width has a minimum of 250 cm (2.5m) for LM calculations
     */
    public function calculateLm(): float
    {
        if ($this->length_cm && $this->width_cm) {
            $lengthM = $this->length_cm / 100;
            $widthCm = max($this->width_cm, 250); // Minimum width of 250 cm
            $widthM = $widthCm / 100;
            return ($lengthM * $widthM) / 2.5;
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

            // Auto-calculate LM if length and width are present
            if ($item->length_cm && $item->width_cm) {
                $item->lm = $item->calculateLm();
            }

            // Auto-calculate line total if unit price is set
            if ($item->unit_price) {
                $item->line_total = $item->calculateLineTotal();
            }
        });

        static::saved(function ($item) {
            // When commodity item dimensions or quantity change, recalculate ALL articles
            // This ensures article prices update when commodity items change
            // For LM articles: quantity is calculated from dimensions
            // For non-LM articles: quantity is updated to sum of all commodity item quantities
            if ($item->quotation_request_id) {
                // Use DB::afterCommit to ensure the transaction is complete before querying
                \DB::afterCommit(function () use ($item) {
                    // Reload the relationship to ensure we have fresh data
                    $quotation = \App\Models\QuotationRequest::find($item->quotation_request_id);
                    if ($quotation) {
                        // Load commodity items to calculate total quantity
                        $quotation->load('commodityItems');
                        $totalCommodityQuantity = $quotation->commodityItems->sum('quantity') ?? 0;
                        
                        // Get ALL articles for this quotation (not just LM)
                        $allArticles = \App\Models\QuotationRequestArticle::where('quotation_request_id', $quotation->id)
                            ->get();
                        
                        \Log::info('QuotationCommodityItem saved - recalculating all articles', [
                            'commodity_item_id' => $item->id,
                            'quotation_request_id' => $quotation->id,
                            'total_articles_count' => $allArticles->count(),
                            'item_quantity' => $item->quantity,
                            'total_commodity_quantity' => $totalCommodityQuantity,
                            'item_lm' => $item->lm,
                            'item_length' => $item->length_cm,
                            'item_width' => $item->width_cm,
                        ]);
                        
                        foreach ($allArticles as $article) {
                            // Reload the article to ensure we have fresh relationship data
                            $article->load('quotationRequest.commodityItems');
                            
                            $oldSubtotal = $article->subtotal;
                            $oldQuantity = $article->quantity;
                            $unitType = strtoupper(trim($article->unit_type ?? ''));
                            
                            // For non-LM articles, update quantity to sum of all commodity item quantities
                            // For LM articles, quantity is calculated by LmQuantityCalculator from dimensions
                            if ($unitType !== 'LM') {
                                // Update quantity to total commodity item quantity
                                // This ensures quantity matches the total number of commodity items
                                $article->quantity = (int) $totalCommodityQuantity;
                            }
                            
                            // Save the article to trigger saving event which recalculates quantity and subtotal
                            // The saving event uses QuantityCalculationService which handles LM calculations
                            // For non-LM, it uses the updated quantity field
                            $article->save();
                            
                            \Log::info('Article recalculated', [
                                'article_id' => $article->id,
                                'unit_type' => $unitType,
                                'old_quantity' => $oldQuantity,
                                'new_quantity' => $article->quantity,
                                'old_subtotal' => $oldSubtotal,
                                'new_subtotal' => $article->subtotal,
                                'effective_quantity' => app(\App\Services\Quotation\QuantityCalculationService::class)->calculateQuantity($article),
                                'selling_price' => $article->selling_price,
                            ]);
                        }
                        
                        // Recalculate quotation totals
                        $quotation->calculateTotals();
                    }
                });
            }
        });
    }
}

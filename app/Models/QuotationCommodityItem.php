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
        'relationship_type',
        'related_item_id',
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
     * Get the related item this item is connected to or loaded with.
     */
    public function relatedItem(): BelongsTo
    {
        return $this->belongsTo(QuotationCommodityItem::class, 'related_item_id');
    }

    /**
     * Get items that are connected to this item.
     */
    public function itemsConnectedToThis(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(QuotationCommodityItem::class, 'related_item_id')
            ->where('relationship_type', 'connected_to');
    }

    /**
     * Get items that are loaded with this item.
     */
    public function itemsLoadedWithThis(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(QuotationCommodityItem::class, 'related_item_id')
            ->where('relationship_type', 'loaded_with');
    }

    /**
     * Check if item is standalone (separate unit).
     */
    public function isSeparate(): bool
    {
        return ($this->relationship_type ?? 'separate') === 'separate';
    }

    /**
     * Check if item is connected to another item.
     */
    public function isConnected(): bool
    {
        return ($this->relationship_type ?? 'separate') === 'connected_to';
    }

    /**
     * Check if item is loaded with another item.
     */
    public function isLoadedWith(): bool
    {
        return ($this->relationship_type ?? 'separate') === 'loaded_with';
    }

    /**
     * Get the line number of the related item for display.
     */
    public function getRelatedItemNumber(): ?int
    {
        if ($this->relatedItem) {
            return $this->relatedItem->line_number;
        }
        return null;
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
     * Find commodity items that match an article's commodity type
     * 
     * @param \Illuminate\Support\Collection $commodityItems
     * @param string $articleCommodityType The article's commodity type (e.g., "CAR", "SMALL VAN")
     * @return \Illuminate\Support\Collection Matching commodity items
     */
    public static function findMatchingCommodityItems($commodityItems, string $articleCommodityType): \Illuminate\Support\Collection
    {
        $articleCommodityTypeUpper = strtoupper(trim($articleCommodityType));
        
        return $commodityItems->filter(function ($item) use ($articleCommodityTypeUpper) {
            // Normalize commodity item to Robaws article types
            $mappedTypes = static::normalizeCommodityTypes($item);
            
            // Check if article's commodity type matches any mapped type
            return in_array($articleCommodityTypeUpper, array_map('strtoupper', $mappedTypes));
        });
    }

    /**
     * Normalize commodity types from commodity item to Robaws article types
     * Reuses logic from SmartArticleSelectionService
     * 
     * @param mixed $commodityItem
     * @return array<string> Array of Robaws commodity type strings
     */
    public static function normalizeCommodityTypes($commodityItem): array
    {
        if (!$commodityItem) {
            return [];
        }

        $type = $commodityItem->commodity_type ?? null;

        // Map internal commodity types to Robaws article types
        $typeMapping = [
            'vehicles' => static::getVehicleCategoryMappings($commodityItem),
            'machinery' => ['Machinery'],
            'boat' => ['Boat'],
            'general_cargo' => ['General Cargo'],
        ];

        return $typeMapping[$type] ?? [];
    }

    /**
     * Get specific vehicle category mappings to Robaws article types
     * 
     * @param mixed $commodityItem
     * @return array<string> Array of Robaws commodity type strings
     */
    public static function getVehicleCategoryMappings($commodityItem): array
    {
        $category = $commodityItem->category ?? $commodityItem->vehicle_category ?? null;

        // Map vehicle categories to Robaws types
        $vehicleMapping = [
            'car' => ['CAR'],
            'suv' => ['SUV'],
            'small_van' => ['SMALL VAN'],
            'big_van' => ['BIG VAN', 'LM CARGO'],
            'truck' => ['TRUCK', 'HH', 'LM CARGO'],
            'truckhead' => ['TRUCKHEAD', 'HH', 'LM CARGO'],
            'bus' => ['BUS', 'HH', 'LM CARGO'],
            'motorcycle' => ['MOTORCYCLE'],
        ];

        return $vehicleMapping[$category] ?? ['CAR'];
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
            // For non-LM articles: quantity is updated based on matching commodity items only
            if ($item->quotation_request_id) {
                // Use DB::afterCommit to ensure the transaction is complete before querying
                \DB::afterCommit(function () use ($item) {
                    // Reload the relationship to ensure we have fresh data
                    $quotation = \App\Models\QuotationRequest::find($item->quotation_request_id);
                    if ($quotation) {
                        // Load commodity items to calculate quantities
                        $quotation->load('commodityItems');
                        $totalCommodityQuantity = $quotation->commodityItems->sum('quantity') ?? 0;
                        
                        // Get ALL articles for this quotation (not just LM)
                        $allArticles = \App\Models\QuotationRequestArticle::where('quotation_request_id', $quotation->id)
                            ->with('articleCache')
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
                            $article->load('quotationRequest.commodityItems', 'articleCache');
                            
                            $oldSubtotal = $article->subtotal;
                            $oldQuantity = $article->quantity;
                            $unitType = strtoupper(trim($article->unit_type ?? ''));
                            
                            // For LM articles, quantity is calculated by LmQuantityCalculator from dimensions
                            // For non-LM articles, calculate quantity based on matching commodity items
                            if ($unitType !== 'LM') {
                                // Get article's commodity type from articleCache
                                $articleCommodityType = $article->articleCache->commodity_type ?? null;
                                
                                if ($articleCommodityType) {
                                    // Find matching commodity items based on commodity type
                                    $matchingItems = static::findMatchingCommodityItems(
                                        $quotation->commodityItems,
                                        $articleCommodityType
                                    );
                                    
                                    // Sum quantities of matching items only
                                    $matchingQuantity = $matchingItems->sum('quantity') ?? 0;
                                    $article->quantity = (int) $matchingQuantity;
                                    
                                    \Log::debug('Article quantity calculated from matching commodity items', [
                                        'article_id' => $article->id,
                                        'article_commodity_type' => $articleCommodityType,
                                        'matching_items_count' => $matchingItems->count(),
                                        'matching_quantity' => $matchingQuantity,
                                    ]);
                                } else {
                                    // Fallback: For articles without commodity_type (e.g., surcharges),
                                    // use sum of all commodity items
                                    // For child articles, use parent's quantity if available
                                    if ($article->parent_article_id) {
                                        // Child article: use parent's quantity
                                        $parentArticle = \App\Models\QuotationRequestArticle::where('quotation_request_id', $quotation->id)
                                            ->where('article_cache_id', $article->parent_article_id)
                                            ->first();
                                        
                                        if ($parentArticle) {
                                            $article->quantity = $parentArticle->quantity;
                                        } else {
                                            $article->quantity = (int) $totalCommodityQuantity;
                                        }
                                    } else {
                                        // Article without commodity type: sum all items
                                        $article->quantity = (int) $totalCommodityQuantity;
                                    }
                                    
                                    \Log::debug('Article quantity calculated from all items (no commodity type)', [
                                        'article_id' => $article->id,
                                        'quantity' => $article->quantity,
                                        'is_child' => $article->parent_article_id !== null,
                                    ]);
                                }
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

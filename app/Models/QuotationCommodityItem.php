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
        'stack_length_cm',
        'stack_width_cm',
        'stack_height_cm',
        'stack_weight_kg',
        'stack_cbm',
        'stack_lm',
        'stack_unit_count',
        'chargeable_lm',
        'carrier_rule_meta',
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
        'chargeable_lm' => 'decimal:4',
        'carrier_rule_meta' => 'array',
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
        'stack_length_cm' => 'decimal:2',
        'stack_width_cm' => 'decimal:2',
        'stack_height_cm' => 'decimal:2',
        'stack_weight_kg' => 'decimal:2',
        'stack_cbm' => 'decimal:4',
        'stack_lm' => 'decimal:4',
        'stack_unit_count' => 'integer',
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
     * Stack Identification and Management Methods
     */

    /**
     * Get the base item ID for this item's stack.
     * Returns this item's ID if it's a base, or the base item's ID if it's in a stack.
     */
    public function getStackGroup(): ?int
    {
        // If this item is separate or is a base (others point to it), it's the base
        if ($this->isSeparate() || $this->isStackBase()) {
            return $this->id;
        }
        
        // If this item is loaded_with or connected_to another, find the base
        if ($this->related_item_id) {
            $baseItem = static::find($this->related_item_id);
            if ($baseItem) {
                // Recursively find the base (in case of chains)
                return $baseItem->getStackGroup();
            }
        }
        
        return $this->id; // Fallback: this item is the base
    }

    /**
     * Get all items in the same stack (base + all items pointing to it).
     */
    public function getStackMembers(): \Illuminate\Support\Collection
    {
        $baseId = $this->getStackGroup();
        
        // Get base item
        $baseItem = static::find($baseId);
        if (!$baseItem) {
            return collect([$this]);
        }
        
        // Get all items that point to this base
        $stackedItems = static::where('quotation_request_id', $this->quotation_request_id)
            ->where(function ($query) use ($baseId) {
                $query->where('id', $baseId)
                    ->orWhere(function ($q) use ($baseId) {
                        $q->where('related_item_id', $baseId)
                            ->whereIn('relationship_type', ['loaded_with', 'connected_to']);
                    });
            })
            ->get();
        
        return $stackedItems;
    }

    /**
     * Check if this item is the base of a stack (others point to it).
     */
    public function isStackBase(): bool
    {
        return static::where('quotation_request_id', $this->quotation_request_id)
            ->where('related_item_id', $this->id)
            ->whereIn('relationship_type', ['loaded_with', 'connected_to'])
            ->exists();
    }

    /**
     * Check if this item is part of any stack.
     */
    public function isInStack(): bool
    {
        return $this->isLoadedWith() || $this->isConnected() || $this->isStackBase();
    }

    /**
     * Get all stacks for a quotation request.
     * Returns array of collections, each collection is a stack.
     */
    public static function getAllStacks(int $quotationRequestId): array
    {
        $allItems = static::where('quotation_request_id', $quotationRequestId)->get();
        $stacks = [];
        $processed = [];
        
        foreach ($allItems as $item) {
            if (in_array($item->id, $processed)) {
                continue;
            }
            
            $baseId = $item->getStackGroup();
            if (!isset($stacks[$baseId])) {
                $stackMembers = $item->getStackMembers();
                $stacks[$baseId] = $stackMembers;
                foreach ($stackMembers as $member) {
                    $processed[] = $member->id;
                }
            }
        }
        
        return array_values($stacks);
    }

    /**
     * Calculate stack CBM from stack dimensions.
     */
    public function calculateStackCbm(): float
    {
        if ($this->stack_length_cm && $this->stack_width_cm && $this->stack_height_cm) {
            return ($this->stack_length_cm * $this->stack_width_cm * $this->stack_height_cm) / 1000000;
        }
        return 0;
    }

    /**
     * Calculate stack LM from stack dimensions using ChargeableMeasureService.
     * Uses carrier-aware transforms if schedule/carrier is available.
     */
    public function calculateStackLm(): float
    {
        if (!$this->stack_length_cm || !$this->stack_width_cm) {
            return 0;
        }

        $service = app(\App\Services\CarrierRules\ChargeableMeasureService::class);
        
        // Try to get carrier context from quotation request
        $carrierId = null;
        $portId = null;
        $vesselName = null;
        $vesselClass = null;
        
        if ($this->quotationRequest) {
            $schedule = $this->quotationRequest->selectedSchedule;
            if ($schedule) {
                $carrierId = $schedule->carrier_id ?? null;
                $portId = $schedule->pod_id ?? null;
                $vesselName = $schedule->vessel_name;
                $vesselClass = $schedule->vessel_class;
            }
        }
        
        $result = $service->computeChargeableLm(
            $this->stack_length_cm,
            $this->stack_width_cm,
            $carrierId,
            $portId,
            $this->category,
            $vesselName,
            $vesselClass
        );
        
        return $result->chargeableLm;
    }

    /**
     * Get total number of units in this stack.
     * Counts base item quantity + sum of all stacked items' quantities.
     */
    public function getStackUnitCount(): int
    {
        $stackMembers = $this->getStackMembers();
        return $stackMembers->sum('quantity') ?? 0;
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
     * Calculate LM (Linear Meter) from dimensions using ChargeableMeasureService.
     * Uses carrier-aware transforms if schedule/carrier is available, otherwise falls back to base ISO LM.
     */
    public function calculateLm(): float
    {
        if (!$this->length_cm || !$this->width_cm) {
            return 0;
        }

        $service = app(\App\Services\CarrierRules\ChargeableMeasureService::class);
        
        // Try to get carrier context from quotation request
        $carrierId = null;
        $portId = null;
        $vesselName = null;
        $vesselClass = null;
        
        if ($this->quotationRequest) {
            $schedule = $this->quotationRequest->selectedSchedule;
            if ($schedule) {
                $carrierId = $schedule->carrier_id ?? null;
                $portId = $schedule->pod_id ?? null;
                $vesselName = $schedule->vessel_name;
                $vesselClass = $schedule->vessel_class;
            }
        }
        
        $result = $service->computeChargeableLm(
            $this->length_cm,
            $this->width_cm,
            $carrierId,
            $portId,
            $this->category, // vehicle category
            $vesselName,
            $vesselClass
        );
        
        // Store chargeable LM and meta for future reference
        $this->chargeable_lm = $result->chargeableLm;
        if (!empty($result->meta)) {
            $this->carrier_rule_meta = array_merge($this->carrier_rule_meta ?? [], $result->meta);
        }
        
        return $result->chargeableLm;
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
            'trailer' => ['TRAILER', 'TRUCK', 'HH', 'LM CARGO'],
            'bus' => ['BUS', 'HH', 'LM CARGO'],
            'motorcycle' => ['MOTORCYCLE'],
            // Truck variants that should map to LM CARGO
            'vacuum_truck' => ['TRUCK', 'HH', 'LM CARGO'],
            'box_truck' => ['TRUCK', 'HH', 'LM CARGO'],
            'platform_truck' => ['TRUCK', 'HH', 'LM CARGO'],
            'tipper_truck' => ['TRUCK', 'HH', 'LM CARGO'],
            'tank_truck' => ['TRUCK', 'HH', 'LM CARGO'],
            'refuse_truck' => ['TRUCK', 'HH', 'LM CARGO'],
            'truck_chassis' => ['TRUCK', 'HH', 'LM CARGO'],
            'concrete_mixer' => ['TRUCK', 'HH', 'LM CARGO'],
            'high_and_heavy' => ['HH', 'LM CARGO'],
            'loaded_truck_trailer' => ['TRUCK', 'HH', 'LM CARGO'],
            'truck_trailer_combination' => ['TRUCK', 'HH', 'LM CARGO'],
        ];

        return $vehicleMapping[$category] ?? ['CAR'];
    }

    /**
     * Temporary storage for related item IDs during deletion
     * Key: deleted_item_id, Value: array of related_item_ids
     */
    protected static array $deletedItemRelatedItems = [];

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

            // Auto-calculate stack CBM if stack dimensions are present
            if ($item->stack_length_cm && $item->stack_width_cm && $item->stack_height_cm) {
                $item->stack_cbm = $item->calculateStackCbm();
            }

            // Auto-calculate stack LM if stack length and width are present
            if ($item->stack_length_cm && $item->stack_width_cm) {
                $item->stack_lm = $item->calculateStackLm();
            }

            // Auto-calculate stack unit count if item is a stack base
            if ($item->isStackBase()) {
                $item->stack_unit_count = $item->getStackUnitCount();
            }

            // Auto-calculate line total if unit price is set
            if ($item->unit_price) {
                $item->line_total = $item->calculateLineTotal();
            }
        });

        static::saved(function ($item) {
            // Process through carrier rules engine if schedule/carrier is available
            try {
                $integrationService = app(\App\Services\CarrierRules\CarrierRuleIntegrationService::class);
                $integrationService->processCommodityItem($item);
            } catch (\Exception $e) {
                \Log::error('QuotationCommodityItem: Error processing carrier rules', [
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
                // Continue with other processing even if carrier rules fail
            }

            // Sync bidirectional relationships at database level
            // When Item A is set to "Connected to Item B", automatically set Item B to "Connected to Item A"
            if ($item->related_item_id && in_array($item->relationship_type, ['connected_to', 'loaded_with'])) {
                $relatedItem = static::where('id', $item->related_item_id)
                    ->where('quotation_request_id', $item->quotation_request_id)
                    ->first();
                
                if ($relatedItem) {
                    // Check if reverse relationship is already set (to avoid infinite loops)
                    $needsUpdate = false;
                    if ($relatedItem->relationship_type !== $item->relationship_type || 
                        $relatedItem->related_item_id != $item->id) {
                        $needsUpdate = true;
                    }
                    
                    if ($needsUpdate) {
                        $relatedItem->relationship_type = $item->relationship_type;
                        $relatedItem->related_item_id = $item->id;
                        $relatedItem->saveQuietly(); // Use saveQuietly to avoid triggering saved event again
                        
                        \Log::info('QuotationCommodityItem: Synced bidirectional relationship at database level', [
                            'item_id' => $item->id,
                            'related_item_id' => $item->related_item_id,
                            'relationship_type' => $item->relationship_type,
                        ]);
                    }
                }
            } elseif ($item->relationship_type === 'separate' || !$item->related_item_id) {
                // When relationship is cleared, clear reverse relationship
                // Find items that are related to this item
                $itemsRelatedToThis = static::where('quotation_request_id', $item->quotation_request_id)
                    ->where('related_item_id', $item->id)
                    ->whereIn('relationship_type', ['connected_to', 'loaded_with'])
                    ->get();
                
                foreach ($itemsRelatedToThis as $relatedItem) {
                    // Only clear if the relationship type matches (to avoid clearing wrong relationships)
                    // Actually, if this item is now separate, we should clear all items related to it
                    $relatedItem->relationship_type = 'separate';
                    $relatedItem->related_item_id = null;
                    $relatedItem->saveQuietly();
                    
                    \Log::info('QuotationCommodityItem: Cleared reverse relationship at database level', [
                        'item_id' => $item->id,
                        'cleared_item_id' => $relatedItem->id,
                    ]);
                }
            }
            
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
                            
                            // Check if this is a unit-count-based article (e.g., "Chassis nr")
                            // These articles use stack_unit_count instead of stack count
                            $isUnitCountBased = $unitType === 'CHASSIS NR';
                            
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
                                    
                                    if ($isUnitCountBased) {
                                        // For unit-count-based articles (e.g., "Chassis nr"), sum stack_unit_count
                                        $totalUnitCount = 0;
                                        $processed = [];
                                        
                                        foreach ($matchingItems as $item) {
                                            if (in_array($item->id, $processed)) {
                                                continue;
                                            }
                                            
                                            // Check if item is in a stack
                                            if ($item->isInStack()) {
                                                $baseId = $item->getStackGroup();
                                                if (!in_array($baseId, $processed)) {
                                                    $baseItem = static::find($baseId);
                                                    if ($baseItem) {
                                                        $stackMembers = $baseItem->getStackMembers();
                                                        // Check if any member of this stack matches the article type
                                                        $hasMatchingMember = false;
                                                        foreach ($stackMembers as $member) {
                                                            $memberTypes = static::normalizeCommodityTypes($member);
                                                            if (in_array(strtoupper($articleCommodityType), array_map('strtoupper', $memberTypes))) {
                                                                $hasMatchingMember = true;
                                                                break;
                                                            }
                                                        }
                                                        if ($hasMatchingMember) {
                                                            // Use stack_unit_count if available, otherwise calculate it
                                                            $stackUnitCount = $baseItem->stack_unit_count ?? $baseItem->getStackUnitCount() ?? 0;
                                                            $totalUnitCount += $stackUnitCount;
                                                            foreach ($stackMembers as $member) {
                                                                $processed[] = $member->id;
                                                            }
                                                        }
                                                    }
                                                }
                                            } else {
                                                // Separate item (not in stack) - use its quantity
                                                $totalUnitCount += $item->quantity ?? 1;
                                                $processed[] = $item->id;
                                            }
                                        }
                                        
                                        $article->quantity = (int) $totalUnitCount;
                                        
                                        \Log::debug('Article quantity calculated from stack unit count (unit-count-based)', [
                                            'article_id' => $article->id,
                                            'article_commodity_type' => $articleCommodityType,
                                            'unit_type' => $article->unit_type,
                                            'matching_items_count' => $matchingItems->count(),
                                            'total_unit_count' => $totalUnitCount,
                                        ]);
                                    } else {
                                        // For regular articles, count stacks (each stack = quantity 1) + sum separate item quantities
                                        // Note: "stack" is the internal term for a group of related items (combinations/loaded combinations/loaded units)
                                        $stacks = [];
                                        $separateItemQuantity = 0;
                                        $processed = [];
                                        
                                        foreach ($matchingItems as $item) {
                                            if (in_array($item->id, $processed)) {
                                                continue;
                                            }
                                            
                                            // Check if item is in a stack (combination/loaded combination/loaded unit)
                                            if ($item->isInStack()) {
                                                $baseId = $item->getStackGroup();
                                                if (!isset($stacks[$baseId])) {
                                                    $stackMembers = $item->getStackMembers();
                                                    // Check if any member of this stack matches the article type
                                                    $hasMatchingMember = false;
                                                    foreach ($stackMembers as $member) {
                                                        $memberTypes = static::normalizeCommodityTypes($member);
                                                        if (in_array(strtoupper($articleCommodityType), array_map('strtoupper', $memberTypes))) {
                                                            $hasMatchingMember = true;
                                                            break;
                                                        }
                                                    }
                                                    if ($hasMatchingMember) {
                                                        $stacks[$baseId] = true;
                                                        foreach ($stackMembers as $member) {
                                                            $processed[] = $member->id;
                                                        }
                                                    }
                                                }
                                            } else {
                                                // Separate item - use its actual quantity field
                                                $separateItemQuantity += $item->quantity ?? 1;
                                                $processed[] = $item->id;
                                            }
                                        }
                                        
                                        // Total quantity = stack count (each group = 1) + separate item quantities (sum of quantity fields)
                                        $stackCount = count($stacks);
                                        $article->quantity = (int) ($stackCount + $separateItemQuantity);
                                        
                                        \Log::debug('Article quantity calculated from stacks and separate items', [
                                            'article_id' => $article->id,
                                            'article_commodity_type' => $articleCommodityType,
                                            'matching_items_count' => $matchingItems->count(),
                                            'stack_count' => $stackCount,
                                            'separate_item_quantity' => $separateItemQuantity,
                                            'total_quantity' => $article->quantity,
                                            'old_method_quantity' => $matchingItems->sum('quantity'),
                                        ]);
                                    }
                                } else {
                                    // Fallback: For articles without commodity_type (e.g., surcharges),
                                    // For child articles, use parent's quantity if available
                                    if ($article->parent_article_id) {
                                        // Child article: use parent's quantity
                                        $parentArticle = \App\Models\QuotationRequestArticle::where('quotation_request_id', $quotation->id)
                                            ->where('article_cache_id', $article->parent_article_id)
                                            ->first();
                                        
                                        if ($parentArticle) {
                                            $article->quantity = $parentArticle->quantity;
                                        } else {
                                            // If unit-count-based, sum stack_unit_count; otherwise sum all items
                                            if ($isUnitCountBased) {
                                                $totalUnitCount = 0;
                                                foreach ($quotation->commodityItems as $item) {
                                                    if ($item->isInStack() && $item->isStackBase()) {
                                                        $totalUnitCount += $item->stack_unit_count ?? $item->getStackUnitCount() ?? 0;
                                                    } else if ($item->isSeparate()) {
                                                        $totalUnitCount += $item->quantity ?? 1;
                                                    }
                                                }
                                                $article->quantity = (int) $totalUnitCount;
                                            } else {
                                                $article->quantity = (int) $totalCommodityQuantity;
                                            }
                                        }
                                    } else {
                                        // Article without commodity type
                                        if ($isUnitCountBased) {
                                            // Sum stack_unit_count for all stacks + individual item quantities
                                            $totalUnitCount = 0;
                                            $processed = [];
                                            
                                            foreach ($quotation->commodityItems as $item) {
                                                if (in_array($item->id, $processed)) {
                                                    continue;
                                                }
                                                
                                                if ($item->isInStack()) {
                                                    $baseId = $item->getStackGroup();
                                                    if (!in_array($baseId, $processed)) {
                                                        $baseItem = static::find($baseId);
                                                        if ($baseItem) {
                                                            $stackUnitCount = $baseItem->stack_unit_count ?? $baseItem->getStackUnitCount() ?? 0;
                                                            $totalUnitCount += $stackUnitCount;
                                                            $stackMembers = $baseItem->getStackMembers();
                                                            foreach ($stackMembers as $member) {
                                                                $processed[] = $member->id;
                                                            }
                                                        }
                                                    }
                                                } else {
                                                    $totalUnitCount += $item->quantity ?? 1;
                                                    $processed[] = $item->id;
                                                }
                                            }
                                            $article->quantity = (int) $totalUnitCount;
                                        } else {
                                            // Sum all items (regular behavior)
                                            $article->quantity = (int) $totalCommodityQuantity;
                                        }
                                    }
                                    
                                    \Log::debug('Article quantity calculated from all items (no commodity type)', [
                                        'article_id' => $article->id,
                                        'quantity' => $article->quantity,
                                        'is_child' => $article->parent_article_id !== null,
                                        'is_unit_count_based' => $isUnitCountBased,
                                        'unit_type' => $article->unit_type,
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

        // Handle item deletion: reprocess related items (especially trailers) when a truck is removed
        static::deleting(function ($item) {
            // Capture items that are related to this item BEFORE deletion
            // The foreign key constraint will set their related_item_id to null, so we need to capture them now
            $relatedItems = static::where('quotation_request_id', $item->quotation_request_id)
                ->where('related_item_id', $item->id)
                ->whereIn('relationship_type', ['connected_to', 'loaded_with'])
                ->get();
            
            // Store the IDs in static array so we can access them in the deleted event
            static::$deletedItemRelatedItems[$item->id] = [
                'quotation_request_id' => $item->quotation_request_id,
                'related_item_ids' => $relatedItems->pluck('id')->toArray(),
            ];
            
            \Log::info('QuotationCommodityItem: Item being deleted, found related items to reprocess', [
                'deleted_item_id' => $item->id,
                'deleted_item_category' => $item->category,
                'quotation_request_id' => $item->quotation_request_id,
                'related_items_count' => $relatedItems->count(),
                'related_item_ids' => $relatedItems->pluck('id')->toArray(),
                'related_item_categories' => $relatedItems->pluck('category')->toArray(),
            ]);
        });

        static::deleted(function ($item) {
            // Get the related item IDs that were captured in the deleting event
            $deletionData = static::$deletedItemRelatedItems[$item->id] ?? null;
            
            if (!$deletionData || empty($deletionData['related_item_ids'])) {
                // Clean up the static array
                unset(static::$deletedItemRelatedItems[$item->id]);
                return; // No related items to reprocess
            }

            $relatedItemIds = $deletionData['related_item_ids'];
            $quotationRequestId = $deletionData['quotation_request_id'];

            // Clean up the static array
            unset(static::$deletedItemRelatedItems[$item->id]);

            // Use DB::afterCommit to ensure the deletion transaction is complete
            \DB::afterCommit(function () use ($item, $relatedItemIds, $quotationRequestId) {
                $integrationService = app(\App\Services\CarrierRules\CarrierRuleIntegrationService::class);
                
                \Log::info('QuotationCommodityItem: Reprocessing related items after deletion', [
                    'deleted_item_id' => $item->id,
                    'quotation_request_id' => $quotationRequestId,
                    'related_item_ids' => $relatedItemIds,
                ]);

                // Reprocess each related item through carrier rules
                // This will update towing surcharges if the deleted item was a truck/truckhead
                // Also recalculates LM when trailer is deleted (truck LM should be recalculated)
                foreach ($relatedItemIds as $relatedItemId) {
                    try {
                        $relatedItem = static::find($relatedItemId);
                        if ($relatedItem && $relatedItem->quotation_request_id === $quotationRequestId) {
                            // Clear the relationship (it should already be null due to FK constraint, but ensure it)
                            if ($relatedItem->related_item_id === $item->id) {
                                $relatedItem->relationship_type = 'separate';
                                $relatedItem->related_item_id = null;
                                $relatedItem->saveQuietly();
                            }
                            
                            // Reprocess through carrier rules to update surcharges (e.g., add towing if trailer is now standalone)
                            $integrationService->processCommodityItem($relatedItem);
                            
                            // Explicitly recalculate LM after reprocessing to ensure it reflects current state
                            // This is especially important when a trailer is deleted - truck LM should be recalculated
                            if ($relatedItem->length_cm && $relatedItem->width_cm) {
                                $oldLm = $relatedItem->lm;
                                $relatedItem->lm = $relatedItem->calculateLm();
                                
                                \Log::info('QuotationCommodityItem: Recalculated LM after deletion', [
                                    'deleted_item_id' => $item->id,
                                    'reprocessed_item_id' => $relatedItem->id,
                                    'old_lm' => $oldLm,
                                    'new_lm' => $relatedItem->lm,
                                ]);
                            }
                            
                            // Save normally (not saveQuietly) to trigger saved event which recalculates all articles
                            // This ensures LM articles are updated when commodity items change
                            $relatedItem->save();
                            
                            \Log::info('QuotationCommodityItem: Reprocessed related item after deletion', [
                                'deleted_item_id' => $item->id,
                                'reprocessed_item_id' => $relatedItem->id,
                                'reprocessed_item_category' => $relatedItem->category,
                                'lm_recalculated' => $relatedItem->length_cm && $relatedItem->width_cm,
                            ]);
                        }
                    } catch (\Exception $e) {
                        \Log::error('QuotationCommodityItem: Error reprocessing related item after deletion', [
                            'deleted_item_id' => $item->id,
                            'related_item_id' => $relatedItemId,
                            'error' => $e->getMessage(),
                        ]);
                        // Continue with other items even if one fails
                    }
                }
            });
        });
    }
}

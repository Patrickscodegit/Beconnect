<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\Quotation\QuantityCalculationService;
use App\Models\QuotationCommodityItem;

class QuotationRequestArticle extends Model
{
    protected $fillable = [
        'quotation_request_id',
        'article_cache_id',
        'parent_article_id',
        'item_type',
        'quantity',
        'unit_type',
        'unit_price',
        'selling_price',
        'subtotal',
        'vat_code', // Robaws VAT code for this article line
        'currency',
        'formula_inputs',
        'calculated_price',
        'notes',
        'carrier_rule_applied',
        'carrier_rule_event_code',
        'carrier_rule_commodity_item_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_type' => 'string',
        'unit_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'calculated_price' => 'decimal:2',
        'formula_inputs' => 'array',
        'carrier_rule_applied' => 'boolean',
        'carrier_rule_commodity_item_id' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            // Calculate price from formula if applicable
            if ($model->formula_inputs && $model->articleCache && $model->articleCache->pricing_formula) {
                $model->calculated_price = $model->articleCache->calculateFormulaPrice($model->formula_inputs);
                $model->selling_price = $model->calculated_price;
            }
            
            // Calculate effective quantity based on unit type (LM, CBM, etc.)
            // This uses the QuantityCalculationService to handle different calculation strategies
            $calculationService = app(QuantityCalculationService::class);
            $effectiveQuantity = $calculationService->calculateQuantity($model);
            
            // Update stored quantity to match calculated quantity for LM/CBM articles
            // This ensures the quantity field reflects the actual calculated value
            $unitType = strtoupper(trim($model->unit_type ?? ''));
            if (in_array($unitType, ['LM', 'CBM'])) {
                $model->quantity = $effectiveQuantity;
            }
            
            // Calculate subtotal
            // For LM: effectiveQuantity already includes commodity item quantity multiplication
            // Formula: LM (from calculator, already × item qty) × LM_price
            // For other unit types: effective_quantity × selling_price
            $model->subtotal = $effectiveQuantity * $model->selling_price;
        });

        static::saved(function ($model) {
            // Ensure articleCache relationship is loaded
            if (!$model->relationLoaded('articleCache')) {
                $model->load('articleCache');
            }
            
            $articleCache = $model->articleCache;
            $isParentItem = $articleCache ? $articleCache->is_parent_item : false;

            \Log::info('QuotationRequestArticle saved', [
                'id' => $model->id,
                'quotation_request_id' => $model->quotation_request_id,
                'item_type' => $model->item_type,
                'article_cache_id' => $model->article_cache_id,
                'parent_article_id' => $model->parent_article_id,
                'has_article_cache' => $articleCache !== null,
                'is_parent_item' => $isParentItem,
                'article_name' => $articleCache->article_name ?? 'N/A',
            ]);

            // Auto-correct item_type if article is actually a parent but was saved as standalone
            // This can happen if the article was updated to be a parent after being added to quotation
            // Also check if article has children even if is_parent_item flag is not set
            $hasChildren = false;
            if ($articleCache && !$articleCache->relationLoaded('children')) {
                $articleCache->load('children');
            }
            if ($articleCache) {
                $hasChildren = $articleCache->children()->count() > 0;
            }
            
            if ($model->item_type !== 'parent' 
                && $model->item_type !== 'child' 
                && $articleCache 
                && ($isParentItem || $hasChildren)
                && !$model->parent_article_id) {
                \Log::info('Auto-correcting item_type from standalone to parent', [
                    'quotation_request_article_id' => $model->id,
                    'article_id' => $model->article_cache_id,
                    'old_item_type' => $model->item_type,
                    'new_item_type' => 'parent',
                    'is_parent_item_flag' => $isParentItem,
                    'has_children' => $hasChildren,
                ]);
                $model->item_type = 'parent';
                $model->save(); // This will trigger saved event again, but with correct item_type
                return; // Exit early, let the next saved event handle addChildArticles()
            }
            
            // When parent article is added, automatically add children
            // Check both is_parent_item flag and if article has children (fallback)
            $hasChildren = false;
            if ($articleCache) {
                if (!$articleCache->relationLoaded('children')) {
                    $articleCache->load('children');
                }
                $hasChildren = $articleCache->children()->count() > 0;
            }
            
            $shouldAddChildren = ($model->item_type === 'parent' && $articleCache && ($isParentItem || $hasChildren));
            
            \Log::info('Checking if should add child articles', [
                'quotation_request_article_id' => $model->id,
                'item_type' => $model->item_type,
                'item_type_is_parent' => $model->item_type === 'parent',
                'has_article_cache' => $articleCache !== null,
                'is_parent_item' => $isParentItem,
                'has_children' => $hasChildren,
                'should_add_children' => $shouldAddChildren,
            ]);
            
            if ($shouldAddChildren) {
                \Log::info('Calling addChildArticles()', [
                    'quotation_request_article_id' => $model->id,
                    'article_id' => $model->article_cache_id,
                ]);
                $model->addChildArticles();
            } else {
                \Log::info('Skipping addChildArticles()', [
                    'quotation_request_article_id' => $model->id,
                    'reason' => $model->item_type !== 'parent' ? 'item_type is not parent' : ($articleCache === null ? 'articleCache is null' : (!$isParentItem && !$hasChildren ? 'is_parent_item is false and no children found' : 'unknown')),
                    'item_type' => $model->item_type,
                    'has_article_cache' => $articleCache !== null,
                    'is_parent_item' => $isParentItem,
                    'has_children' => $hasChildren,
                ]);
            }
            
            // Recalculate article quantity based on commodity items when article is first created
            // This ensures articles get the correct quantity even if commodity items were added before the article
            if ($model->quotationRequest && $articleCache) {
                $unitType = strtoupper(trim($model->unit_type ?? ''));
                
                // Only recalculate for non-LM articles (LM is calculated by QuantityCalculationService)
                if ($unitType !== 'LM') {
                    $articleCommodityType = $articleCache->commodity_type ?? null;
                    
                    if ($articleCommodityType) {
                        // Load commodity items
                        $quotation = $model->quotationRequest;
                        if (!$quotation->relationLoaded('commodityItems')) {
                            $quotation->load('commodityItems');
                        }
                        
                        // Find matching commodity items
                        $matchingItems = QuotationCommodityItem::findMatchingCommodityItems(
                            $quotation->commodityItems,
                            $articleCommodityType
                        );
                        
                        if ($matchingItems->isNotEmpty()) {
                            $oldQuantity = $model->quantity;
                            $isUnitCountBased = $unitType === 'CHASSIS NR';
                            
                            if ($isUnitCountBased) {
                                // For unit-count-based articles (e.g., "Chassis nr"), sum stack_unit_count
                                $totalUnitCount = 0;
                                $processed = [];
                                
                                foreach ($matchingItems as $item) {
                                    if (in_array($item->id, $processed)) {
                                        continue;
                                    }
                                    
                                    if ($item->isInStack()) {
                                        $baseId = $item->getStackGroup();
                                        if (!in_array($baseId, $processed)) {
                                            $baseItem = QuotationCommodityItem::find($baseId);
                                            if ($baseItem) {
                                                $stackMembers = $baseItem->getStackMembers();
                                                $hasMatchingMember = false;
                                                foreach ($stackMembers as $member) {
                                                    $memberTypes = QuotationCommodityItem::normalizeCommodityTypes($member);
                                                    if (in_array(strtoupper($articleCommodityType), array_map('strtoupper', $memberTypes))) {
                                                        $hasMatchingMember = true;
                                                        break;
                                                    }
                                                }
                                                if ($hasMatchingMember) {
                                                    $stackUnitCount = $baseItem->stack_unit_count ?? $baseItem->getStackUnitCount() ?? 0;
                                                    $totalUnitCount += $stackUnitCount;
                                                    foreach ($stackMembers as $member) {
                                                        $processed[] = $member->id;
                                                    }
                                                }
                                            }
                                        }
                                    } else {
                                        $totalUnitCount += $item->quantity ?? 1;
                                        $processed[] = $item->id;
                                    }
                                }
                                
                                $model->quantity = (int) $totalUnitCount;
                            } else {
                                // For regular articles, count stacks + sum separate item quantities
                                $stacks = [];
                                $separateItemQuantity = 0;
                                $processed = [];
                                
                                foreach ($matchingItems as $item) {
                                    if (in_array($item->id, $processed)) {
                                        continue;
                                    }
                                    
                                    if ($item->isInStack()) {
                                        $baseId = $item->getStackGroup();
                                        if (!isset($stacks[$baseId])) {
                                            $stackMembers = $item->getStackMembers();
                                            $hasMatchingMember = false;
                                            foreach ($stackMembers as $member) {
                                                $memberTypes = QuotationCommodityItem::normalizeCommodityTypes($member);
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
                                        $separateItemQuantity += $item->quantity ?? 1;
                                        $processed[] = $item->id;
                                    }
                                }
                                
                                $stackCount = count($stacks);
                                $model->quantity = (int) ($stackCount + $separateItemQuantity);
                            }
                            
                            // Save again to trigger saving event which recalculates subtotal
                            if ($model->quantity != $oldQuantity) {
                                $model->save();
                                
                                \Log::info('Article quantity recalculated on creation', [
                                    'article_id' => $model->id,
                                    'article_commodity_type' => $articleCommodityType,
                                    'old_quantity' => $oldQuantity,
                                    'new_quantity' => $model->quantity,
                                    'matching_items_count' => $matchingItems->count(),
                                ]);
                            }
                        }
                    } else {
                        // Article without commodity_type (e.g., surcharges, child articles)
                        // Load commodity items
                        $quotation = $model->quotationRequest;
                        if (!$quotation->relationLoaded('commodityItems')) {
                            $quotation->load('commodityItems');
                        }
                        
                        $oldQuantity = $model->quantity;
                        $isUnitCountBased = $unitType === 'CHASSIS NR';
                        
                        // For child articles, use parent's quantity if available
                        if ($model->parent_article_id) {
                            $parentArticle = self::where('quotation_request_id', $quotation->id)
                                ->where('article_cache_id', $model->parent_article_id)
                                ->first();
                            
                            if ($parentArticle) {
                                $model->quantity = $parentArticle->quantity;
                            } else {
                                // Parent not found, calculate from all items
                                if ($isUnitCountBased) {
                                    // For unit-count-based articles (e.g., "Chassis nr"), sum stack_unit_count
                                    $totalUnitCount = 0;
                                    $processed = [];
                                    
                                    foreach ($quotation->commodityItems as $item) {
                                        if (in_array($item->id, $processed)) {
                                            continue;
                                        }
                                        
                                        if ($item->isInStack()) {
                                            $baseId = $item->getStackGroup();
                                            if (!in_array($baseId, $processed)) {
                                                $baseItem = QuotationCommodityItem::find($baseId);
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
                                    
                                    $model->quantity = (int) $totalUnitCount;
                                } else {
                                    // Sum all commodity items
                                    $totalCommodityQuantity = $quotation->commodityItems->sum('quantity') ?? 0;
                                    $model->quantity = (int) $totalCommodityQuantity;
                                }
                            }
                        } else {
                            // Article without commodity type and not a child
                            if ($isUnitCountBased) {
                                // For unit-count-based articles (e.g., "Chassis nr"), sum stack_unit_count
                                $totalUnitCount = 0;
                                $processed = [];
                                
                                foreach ($quotation->commodityItems as $item) {
                                    if (in_array($item->id, $processed)) {
                                        continue;
                                    }
                                    
                                    if ($item->isInStack()) {
                                        $baseId = $item->getStackGroup();
                                        if (!in_array($baseId, $processed)) {
                                            $baseItem = QuotationCommodityItem::find($baseId);
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
                                
                                $model->quantity = (int) $totalUnitCount;
                            } else {
                                // Sum all commodity items
                                $totalCommodityQuantity = $quotation->commodityItems->sum('quantity') ?? 0;
                                $model->quantity = (int) $totalCommodityQuantity;
                            }
                        }
                        
                        // Save again to trigger saving event which recalculates subtotal
                        if ($model->quantity != $oldQuantity) {
                            $model->save();
                            
                            \Log::info('Article quantity recalculated on creation (no commodity type)', [
                                'article_id' => $model->id,
                                'old_quantity' => $oldQuantity,
                                'new_quantity' => $model->quantity,
                                'is_child' => $model->parent_article_id !== null,
                                'is_unit_count_based' => $isUnitCountBased,
                                'unit_type' => $unitType,
                            ]);
                        }
                    }
                }
            }
            
            // Recalculate parent quotation totals
            if ($model->quotationRequest) {
                $model->quotationRequest->calculateTotals();
            }
        });

        static::deleted(function ($model) {
            // When a parent article is deleted, delete its children
            if ($model->item_type === 'parent') {
                self::where('quotation_request_id', $model->quotation_request_id)
                    ->where('parent_article_id', $model->article_cache_id)
                    ->delete();
            }
            
            // Recalculate parent quotation totals
            if ($model->quotationRequest) {
                $model->quotationRequest->calculateTotals();
            }
        });
    }

    /**
     * Relationships
     */
    
    public function quotationRequest(): BelongsTo
    {
        return $this->belongsTo(QuotationRequest::class);
    }

    /**
     * Get calculation breakdown for LM articles
     * Returns array with: lm_per_item, quantity, total_lm, price, subtotal
     * 
     * @return array|null Returns null if not an LM article or no commodity items
     */
    public function getLmCalculationBreakdown(): ?array
    {
        if (strtoupper(trim($this->unit_type ?? '')) !== 'LM') {
            return null;
        }

        $quotationRequest = $this->quotationRequest;
        if (!$quotationRequest) {
            return null;
        }

        // Load commodity items if not already loaded
        if (!$quotationRequest->relationLoaded('commodityItems')) {
            $quotationRequest->load('commodityItems');
        }
        
        // Load schedule with carrier for carrier-aware calculations
        if (!$quotationRequest->relationLoaded('selectedSchedule')) {
            $quotationRequest->load('selectedSchedule.carrier');
        }

        $commodityItems = $quotationRequest->commodityItems;
        if ($commodityItems->isEmpty()) {
            return null;
        }

        $service = app(\App\Services\CarrierRules\ChargeableMeasureService::class);
        $schedule = $quotationRequest->selectedSchedule;
        
        // Get carrier context from schedule
        $carrierId = $schedule?->carrier_id;
        $portId = $schedule?->pod_id; // Fixed: use pod_id instead of pod_port_id
        $vesselName = $schedule?->vessel_name;
        $vesselClass = $schedule?->vessel_class;

        $lmPerItem = 0;
        $totalQuantity = 0;
        $totalLm = 0;

        foreach ($commodityItems as $item) {
            if ($item->length_cm && $item->width_cm) {
                // Use ChargeableMeasureService for carrier-aware LM calculation
                $result = $service->computeChargeableLm(
                    $item->length_cm,
                    $item->width_cm,
                    $carrierId,
                    $portId,
                    $item->category,
                    $vesselName,
                    $vesselClass
                );
                
                $itemLmPerItem = $result->chargeableLm;
                
                $itemQuantity = $item->quantity ?? 1;
                $itemTotalLm = $itemLmPerItem * $itemQuantity;
                
                // For display, use the first item's LM per item (assuming all items have same dimensions)
                if ($lmPerItem == 0) {
                    $lmPerItem = $itemLmPerItem;
                }
                
                $totalQuantity += $itemQuantity;
                $totalLm += $itemTotalLm;
            }
        }

        if ($totalLm == 0) {
            return null;
        }

        return [
            'lm_per_item' => round($lmPerItem, 2),
            'quantity' => $totalQuantity,
            'total_lm' => round($totalLm, 2),
            'price' => $this->selling_price ?? $this->unit_price ?? 0,
            'subtotal' => $this->subtotal ?? 0,
        ];
    }

    public function articleCache(): BelongsTo
    {
        return $this->belongsTo(RobawsArticleCache::class, 'article_cache_id');
    }

    public function parentArticle(): BelongsTo
    {
        return $this->belongsTo(RobawsArticleCache::class, 'parent_article_id');
    }

    /**
     * Get the effective/display quantity for this article.
     * For LM/CBM articles, this calculates the actual quantity from commodity dimensions.
     * For other articles, returns the stored quantity.
     * 
     * @return float
     */
    public function getDisplayQuantityAttribute(): float
    {
        $calculationService = app(QuantityCalculationService::class);
        return $calculationService->calculateQuantity($this);
    }

    /**
     * Auto-add child articles when parent is selected
     */
    public function addChildArticles(): void
    {
        // Ensure articleCache relationship is loaded
        if (!$this->relationLoaded('articleCache')) {
            $this->load('articleCache');
        }
        
        // Validate articleCache exists
        if (!$this->articleCache) {
            \Log::error('addChildArticles called but articleCache not found', [
                'quotation_request_id' => $this->quotation_request_id,
                'article_cache_id' => $this->article_cache_id,
            ]);
            return;
        }
        
        \Log::info('addChildArticles called', [
            'quotation_request_id' => $this->quotation_request_id,
            'article_cache_id' => $this->article_cache_id,
            'item_type' => $this->item_type,
            'article_name' => $this->articleCache->article_name ?? 'N/A',
            'is_parent_item' => $this->articleCache->is_parent_item ?? false,
        ]);
        
        // Ensure children relationship is loaded with pivot data
        if (!$this->articleCache->relationLoaded('children')) {
            $this->articleCache->load('children');
        }
        
        $children = $this->articleCache->children ?? collect();
        \Log::info('Children found', [
            'count' => $children->count(),
            'children' => $children->map(fn($c) => [
                'id' => $c->id,
                'article_code' => $c->article_code,
                'article_name' => $c->article_name,
                'child_type' => $c->pivot->child_type ?? 'not_set',
            ])->toArray(),
        ]);
        
        $quotationRequest = $this->quotationRequest;
        $role = $quotationRequest->customer_role;
        
        $conditionMatcher = app(\App\Services\CompositeItems\ConditionMatcherService::class);
        
        // Find admin articles dynamically to identify them
        $admin75 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 75')->where('unit_price', 75)->first();
        $admin100 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 100')->where('unit_price', 100)->first();
        $admin110 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 110')->where('unit_price', 110)->first();
        $admin115 = \App\Models\RobawsArticleCache::where('article_name', 'Admin')->where('unit_price', 115)->first();
        $admin125 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 125')->where('unit_price', 125)->first();
        
        $adminArticleIds = array_filter([
            $admin75 ? $admin75->id : null,
            $admin100 ? $admin100->id : null,
            $admin110 ? $admin110->id : null,
            $admin115 ? $admin115->id : null,
            $admin125 ? $admin125->id : null,
        ]);
        
        // Separate admin articles from other children
        $adminChildren = collect();
        $otherChildren = collect();
        
        foreach ($children as $child) {
            if (in_array($child->id, $adminArticleIds)) {
                $adminChildren->push($child);
            } else {
                $otherChildren->push($child);
            }
        }
        
        // Process admin articles: Check POD match, then add the attached admin article
        if ($adminChildren->count() > 0) {
            // Check POD match between parent and quotation (100% match required)
            $parentPodCode = $this->extractPodCode($this->articleCache);
            $quotationPodCode = $this->extractPodCodeFromQuotation($quotationRequest);
            
            // Only process admin article if POD matches
            if ($parentPodCode && $quotationPodCode && strtoupper(trim($parentPodCode)) === strtoupper(trim($quotationPodCode))) {
                // Get the admin article attached to this parent (should be only one)
                $adminChild = $adminChildren->first();
                
                if ($adminChild) {
                    // Check for generic "per shipment" deduplication
                    $childUnitType = strtoupper(trim($adminChild->pivot->unit_type ?? $adminChild->unit_type ?? ''));
                    $isPerShipment = in_array($childUnitType, ['SHIPM.', 'SHIPM', 'SHIPMENT']);
                    
                    // If "per shipment", check if any "per shipment" article already exists
                    $exists = false;
                    if ($isPerShipment) {
                        $exists = self::where('quotation_request_id', $quotationRequest->id)
                            ->where(function ($query) {
                                $query->whereRaw('UPPER(TRIM(unit_type)) = ?', ['SHIPM.'])
                                      ->orWhereRaw('UPPER(TRIM(unit_type)) = ?', ['SHIPM'])
                                      ->orWhereRaw('UPPER(TRIM(unit_type)) = ?', ['SHIPMENT']);
                            })
                            ->exists();
                    }
                    
                    if (!$exists) {
                        try {
                            $quantity = 1; // Per shipment = quantity 1
                            
                            $created = self::create([
                                'quotation_request_id' => $quotationRequest->id,
                                'article_cache_id' => $adminChild->id,
                                'parent_article_id' => $this->article_cache_id,
                                'item_type' => 'child',
                                'quantity' => $quantity,
                                'unit_type' => $adminChild->pivot->unit_type ?? $adminChild->unit_type ?? 'unit',
                                'unit_price' => $adminChild->pivot->default_cost_price ?? $adminChild->unit_price,
                                'selling_price' => $adminChild->getPriceForRole($role ?: 'default'),
                                'currency' => $adminChild->currency,
                            ]);
                            
                            \Log::info('Admin article added (POD matched)', [
                                'admin_article_id' => $adminChild->id,
                                'admin_article_name' => $adminChild->article_name,
                                'parent_pod' => $parentPodCode,
                                'quotation_pod' => $quotationPodCode,
                                'quotation_request_article_id' => $created->id,
                            ]);
                        } catch (\Exception $e) {
                            \Log::error('Failed to create admin article', [
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);
                        }
                    } else {
                        \Log::info('Admin article skipped (per shipment article already exists)', [
                            'admin_article_id' => $adminChild->id,
                            'parent_pod' => $parentPodCode,
                            'quotation_pod' => $quotationPodCode,
                        ]);
                    }
                }
            } else {
                \Log::info('Admin article skipped (POD mismatch)', [
                    'parent_pod' => $parentPodCode,
                    'quotation_pod' => $quotationPodCode,
                    'parent_article_id' => $this->article_cache_id,
                ]);
            }
        }
        
        // Process other (non-admin) children
        foreach ($otherChildren as $child) {
            $childType = $child->pivot->child_type ?? 'optional';
            $shouldAdd = false;
            
            \Log::info('Processing child', [
                'child_id' => $child->id,
                'child_code' => $child->article_code,
                'child_name' => $child->article_name,
                'child_type' => $childType,
            ]);
            
            // Determine if child should be added based on child_type
            if ($childType === 'mandatory') {
                $shouldAdd = true;
                \Log::info('Child is mandatory - will add');
            } elseif ($childType === 'conditional') {
                // Add conditional items only if conditions match
                try {
                    $conditions = is_string($child->pivot->conditions) 
                        ? json_decode($child->pivot->conditions, true) 
                        : $child->pivot->conditions;
                    
                    // Validate JSON was parsed correctly
                    if (is_string($child->pivot->conditions) && json_last_error() !== JSON_ERROR_NONE) {
                        throw new \InvalidArgumentException('Invalid conditions JSON: ' . json_last_error_msg());
                    }
                    
                    // Validate conditions is an array
                    if (!is_array($conditions) || empty($conditions)) {
                        \Log::warning('Invalid conditions structure for conditional child', [
                            'child_id' => $child->id,
                            'child_name' => $child->article_name,
                            'conditions' => $child->pivot->conditions,
                        ]);
                        continue;
                    }
                    
                    // Match conditions
                    try {
                        $shouldAdd = $conditionMatcher->matchConditions($conditions, $quotationRequest);
                    } catch (\Exception $e) {
                        \Log::error('Error matching conditions for conditional child', [
                            'child_id' => $child->id,
                            'child_name' => $child->article_name,
                            'conditions' => $conditions,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        // Default to not adding if condition matching fails
                        $shouldAdd = false;
                    }
                    
                    \Log::info('Child is conditional', [
                        'conditions' => $conditions,
                        'should_add' => $shouldAdd,
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Failed to parse conditions for conditional child', [
                        'child_id' => $child->id,
                        'child_name' => $child->article_name,
                        'conditions_raw' => $child->pivot->conditions ?? null,
                        'error' => $e->getMessage(),
                    ]);
                    continue; // Skip this child and continue with others
                }
            } else {
                \Log::info('Child is optional - will not auto-add');
            }
            
            if ($shouldAdd) {
                // Check for "per shipment" deduplication (generic check)
                $childUnitType = strtoupper(trim($child->pivot->unit_type ?? $child->unit_type ?? ''));
                $isPerShipment = in_array($childUnitType, ['SHIPM.', 'SHIPM', 'SHIPMENT']);
                
                $exists = false;
                if ($isPerShipment) {
                    // Check if any "per shipment" article already exists in quotation
                    $exists = self::where('quotation_request_id', $quotationRequest->id)
                        ->where(function ($query) {
                            $query->whereRaw('UPPER(TRIM(unit_type)) = ?', ['SHIPM.'])
                                  ->orWhereRaw('UPPER(TRIM(unit_type)) = ?', ['SHIPM'])
                                  ->orWhereRaw('UPPER(TRIM(unit_type)) = ?', ['SHIPMENT']);
                        })
                        ->exists();
                } else {
                    // For non-per-shipment articles, check if same article already added to this parent
                    $exists = self::where('quotation_request_id', $quotationRequest->id)
                        ->where('article_cache_id', $child->id)
                        ->where('parent_article_id', $this->article_cache_id)
                        ->exists();
                }
                
                \Log::info('Checking if child already exists', [
                    'exists' => $exists,
                    'is_per_shipment' => $isPerShipment,
                ]);
                
                if (!$exists) {
                    try {
                        // Validate child article still exists
                        if (!$child || !$child->id) {
                            \Log::warning('Child article not found or invalid', [
                                'child_id' => $child->id ?? null,
                                'parent_article_id' => $this->article_cache_id,
                            ]);
                            continue;
                        }
                        
                        // Ensure quantity is an integer (default_quantity might be decimal)
                        $quantity = (int) ($child->pivot->default_quantity ?? $this->quantity ?? 1);
                        
                        // Get selling price with error handling
                        $sellingPrice = null;
                        try {
                            $sellingPrice = $child->getPriceForRole($role ?: 'default');
                        } catch (\Exception $e) {
                            \Log::warning('Failed to get selling price for child article, using unit_price', [
                                'child_id' => $child->id,
                                'error' => $e->getMessage(),
                            ]);
                            $sellingPrice = $child->pivot->default_cost_price ?? $child->unit_price ?? 0;
                        }
                        
                        // Ensure we have a valid selling price
                        if ($sellingPrice === null || $sellingPrice === 0) {
                            $sellingPrice = $child->pivot->default_cost_price ?? $child->unit_price ?? 0;
                        }
                        
                        $created = self::create([
                            'quotation_request_id' => $quotationRequest->id,
                            'article_cache_id' => $child->id,
                            'parent_article_id' => $this->article_cache_id,
                            'item_type' => 'child',
                            'quantity' => $quantity,
                            'unit_type' => $child->pivot->unit_type ?? $child->unit_type ?? 'unit',
                            'unit_price' => $child->pivot->default_cost_price ?? $child->unit_price ?? 0,
                            'selling_price' => $sellingPrice,
                            'currency' => $child->currency ?? 'EUR',
                        ]);
                        \Log::info('Child article created successfully', [
                            'quotation_request_article_id' => $created->id,
                            'child_id' => $child->id,
                            'child_name' => $child->article_name,
                        ]);
                    } catch (\Exception $e) {
                        \Log::error('Failed to create child article', [
                            'quotation_request_id' => $quotationRequest->id,
                            'child_id' => $child->id ?? null,
                            'child_name' => $child->article_name ?? 'N/A',
                            'parent_article_id' => $this->article_cache_id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        // Continue processing other children
                    }
                }
            }
        }
    }

    /**
     * Get formatted subtotal
     */
    public function getFormattedSubtotalAttribute(): string
    {
        return $this->currency . ' ' . number_format($this->subtotal, 2);
    }

    /**
     * Check if this is a child article
     */
    public function isChild(): bool
    {
        return $this->item_type === 'child' && $this->parent_article_id !== null;
    }

    /**
     * Check if this is a mandatory child article (cannot be removed)
     */
    public function isMandatoryChild(): bool
    {
        if (!$this->isChild()) {
            return false;
        }
        
        $parent = RobawsArticleCache::find($this->parent_article_id);
        if (!$parent) {
            return false;
        }
        
        $childRelation = $parent->children()->where('robaws_articles_cache.id', $this->article_cache_id)->first();
        if (!$childRelation) {
            return false;
        }
        
        return ($childRelation->pivot->child_type ?? 'optional') === 'mandatory';
    }

    /**
     * Check if this is a parent article
     */
    public function isParent(): bool
    {
        return $this->item_type === 'parent';
    }

    /**
     * Check if this is a standalone article
     */
    public function isStandalone(): bool
    {
        return $this->item_type === 'standalone';
    }

    /**
     * Get child articles for this parent
     */
    public function childArticles()
    {
        if (!$this->isParent()) {
            return collect([]);
        }

        return self::where('quotation_request_id', $this->quotation_request_id)
            ->where('parent_article_id', $this->article_cache_id)
            ->with('articleCache')
            ->get();
    }

    /**
     * Check if this is a mandatory child article
     */
    public function isMandatory(): bool
    {
        if (!$this->isChild() || !$this->parentArticle) {
            return false;
        }
        
        $child = $this->parentArticle->children()
            ->where('robaws_articles_cache.id', $this->article_cache_id)
            ->first();
            
        return $child && ($child->pivot->child_type ?? 'optional') === 'mandatory';
    }

    /**
     * Check if this is an optional child article
     */
    public function isOptional(): bool
    {
        if (!$this->isChild() || !$this->parentArticle) {
            return false;
        }
        
        $child = $this->parentArticle->children()
            ->where('robaws_articles_cache.id', $this->article_cache_id)
            ->first();
            
        return $child && ($child->pivot->child_type ?? 'optional') === 'optional';
    }

    /**
     * Check if this is a conditional child article
     */
    public function isConditional(): bool
    {
        if (!$this->isChild() || !$this->parentArticle) {
            return false;
        }
        
        $child = $this->parentArticle->children()
            ->where('robaws_articles_cache.id', $this->article_cache_id)
            ->first();
            
        return $child && ($child->pivot->child_type ?? 'optional') === 'conditional';
    }

    /**
     * Extract POD code from parent article
     * Checks pod_code first, then pod field, then article_name
     */
    private function extractPodCode(RobawsArticleCache $article): ?string
    {
        // Try pod_code first
        if (!empty($article->pod_code)) {
            return strtoupper(trim($article->pod_code));
        }
        
        // Try pod field (may contain "City (CODE), Country")
        if (!empty($article->pod)) {
            // Extract code from format "City (CODE), Country"
            if (preg_match('/\(([A-Z0-9]+)\)/', $article->pod, $matches)) {
                return strtoupper(trim($matches[1]));
            }
            // If no parentheses, might already be a code
            return strtoupper(trim($article->pod));
        }
        
        // Try extracting from article_name (last resort)
        // This would use ArticleNameParser, but for now return null
        // The migration should have populated pod_code, so this is unlikely
        return null;
    }

    /**
     * Extract POD code from quotation request
     * Checks pod field (may contain "City (CODE), Country")
     */
    private function extractPodCodeFromQuotation(QuotationRequest $quotation): ?string
    {
        if (empty($quotation->pod)) {
            return null;
        }
        
        // Extract code from format "City (CODE), Country"
        if (preg_match('/\(([A-Z0-9]+)\)/', $quotation->pod, $matches)) {
            return strtoupper(trim($matches[1]));
        }
        
        // If no parentheses, might already be a code
        return strtoupper(trim($quotation->pod));
    }
}


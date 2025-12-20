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
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_type' => 'string',
        'unit_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'calculated_price' => 'decimal:2',
        'formula_inputs' => 'array',
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
            $isParentArticle = $articleCache ? $articleCache->is_parent_article : false;
            
            \Log::info('QuotationRequestArticle saved', [
                'id' => $model->id,
                'quotation_request_id' => $model->quotation_request_id,
                'item_type' => $model->item_type,
                'article_cache_id' => $model->article_cache_id,
                'parent_article_id' => $model->parent_article_id,
                'has_article_cache' => $articleCache !== null,
                'is_parent_article' => $isParentArticle,
                'article_name' => $articleCache->article_name ?? 'N/A',
            ]);
            
            // Auto-correct item_type if article is actually a parent but was saved as standalone
            // This can happen if the article was updated to be a parent after being added to quotation
            // Also check if article has children even if is_parent_article flag is not set
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
                && ($isParentArticle || $hasChildren)
                && !$model->parent_article_id) {
                \Log::info('Auto-correcting item_type from standalone to parent', [
                    'quotation_request_article_id' => $model->id,
                    'article_id' => $model->article_cache_id,
                    'old_item_type' => $model->item_type,
                    'new_item_type' => 'parent',
                    'is_parent_article_flag' => $isParentArticle,
                    'has_children' => $hasChildren,
                ]);
                $model->item_type = 'parent';
                $model->save(); // This will trigger saved event again, but with correct item_type
                return; // Exit early, let the next saved event handle addChildArticles()
            }
            
            // When parent article is added, automatically add children
            // Check both is_parent_article flag and if article has children (fallback)
            $hasChildren = false;
            if ($articleCache) {
                if (!$articleCache->relationLoaded('children')) {
                    $articleCache->load('children');
                }
                $hasChildren = $articleCache->children()->count() > 0;
            }
            
            $shouldAddChildren = ($model->item_type === 'parent' && $articleCache && ($isParentArticle || $hasChildren));
            
            \Log::info('Checking if should add child articles', [
                'quotation_request_article_id' => $model->id,
                'item_type' => $model->item_type,
                'item_type_is_parent' => $model->item_type === 'parent',
                'has_article_cache' => $articleCache !== null,
                'is_parent_article' => $isParentArticle,
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
                    'reason' => $model->item_type !== 'parent' ? 'item_type is not parent' : ($articleCache === null ? 'articleCache is null' : (!$isParentArticle && !$hasChildren ? 'is_parent_article is false and no children found' : 'unknown')),
                    'item_type' => $model->item_type,
                    'has_article_cache' => $articleCache !== null,
                    'is_parent_article' => $isParentArticle,
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

        $commodityItems = $quotationRequest->commodityItems;
        if ($commodityItems->isEmpty()) {
            return null;
        }

        $lmPerItem = 0;
        $totalQuantity = 0;
        $totalLm = 0;

        foreach ($commodityItems as $item) {
            if ($item->length_cm && $item->width_cm) {
                // Calculate LM per item: (length_m × max(width_m, 2.5)) / 2.5
                // Width has a minimum of 250 cm (2.5m) for LM calculations
                $lengthM = $item->length_cm / 100;
                $widthCm = max($item->width_cm, 250); // Minimum width of 250 cm
                $widthM = $widthCm / 100;
                $itemLmPerItem = ($lengthM * $widthM) / 2.5;
                
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
        
        \Log::info('addChildArticles called', [
            'quotation_request_id' => $this->quotation_request_id,
            'article_cache_id' => $this->article_cache_id,
            'item_type' => $this->item_type,
            'article_name' => $this->articleCache->article_name ?? 'N/A',
            'is_parent_article' => $this->articleCache->is_parent_article ?? false,
        ]);
        
        // Ensure children relationship is loaded with pivot data
        if (!$this->articleCache->relationLoaded('children')) {
            $this->articleCache->load('children');
        }
        
        $children = $this->articleCache->children;
        \Log::info('Children found', [
            'count' => $children->count(),
            'children' => $children->map(fn($c) => [
                'id' => $c->id,
                'article_code' => $c->article_code,
                'article_name' => $c->article_name,
                'child_type' => $c->pivot->child_type ?? 'not_set',
            ])->toArray(),
        ]);
        
        // #region agent log
        file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode(['sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'D', 'location' => 'QuotationRequestArticle.php:525', 'message' => 'Children loaded from parent', 'data' => ['parent_article_id' => $this->article_cache_id, 'parent_article_name' => $this->articleCache->article_name ?? 'N/A', 'children_count' => $children->count(), 'children_ids' => $children->pluck('id')->toArray(), 'quotation_id' => $quotationRequest->id], 'timestamp' => time() * 1000]) . "\n", FILE_APPEND);
        // #endregion
        
        $quotationRequest = $this->quotationRequest;
        $role = $quotationRequest->customer_role;
        
        $conditionMatcher = app(\App\Services\CompositeItems\ConditionMatcherService::class);
        
        // Find admin articles dynamically (works in both local and production)
        $admin75 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 75')->where('unit_price', 75)->first();
        $admin100 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 100')->where('unit_price', 100)->first();
        $admin110 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 110')->where('unit_price', 110)->first();
        $admin115 = \App\Models\RobawsArticleCache::where('article_name', 'Admin')->where('unit_price', 115)->first();
        $admin125 = \App\Models\RobawsArticleCache::where('article_name', 'Admin 125')->where('unit_price', 125)->first();
        
        $admin75Id = $admin75 ? $admin75->id : null;
        $admin100Id = $admin100 ? $admin100->id : null;
        $admin110Id = $admin110 ? $admin110->id : null;
        $admin115Id = $admin115 ? $admin115->id : null;
        $admin125Id = $admin125 ? $admin125->id : null;
        
        // #region agent log
        file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode(['sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'A', 'location' => 'QuotationRequestArticle.php:541', 'message' => 'Admin article IDs lookup', 'data' => ['admin75_id' => $admin75Id, 'admin100_id' => $admin100Id, 'admin110_id' => $admin110Id, 'admin115_id' => $admin115Id, 'admin125_id' => $admin125Id, 'quotation_id' => $quotationRequest->id], 'timestamp' => time() * 1000]) . "\n", FILE_APPEND);
        // #endregion
        
        $adminArticleIds = array_filter([$admin75Id, $admin100Id, $admin110Id, $admin115Id, $admin125Id]);
        
        // Check if any admin article already exists in the quotation
        $existingAdminArticle = self::where('quotation_request_id', $quotationRequest->id)
            ->whereIn('article_cache_id', $adminArticleIds)
            ->first();
        
        $adminArticleAdded = false;
        
        // Separate admin articles from other children for priority-based evaluation
        $adminChildren = collect();
        $otherChildren = collect();
        
        foreach ($children as $child) {
            if (in_array($child->id, $adminArticleIds)) {
                $adminChildren->push($child);
            } else {
                $otherChildren->push($child);
            }
        }
        
        // #region agent log
        file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode(['sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'A', 'location' => 'QuotationRequestArticle.php:560', 'message' => 'Admin children separation', 'data' => ['admin_children_count' => $adminChildren->count(), 'admin_children_ids' => $adminChildren->pluck('id')->toArray(), 'admin_article_ids_lookup' => $adminArticleIds, 'existing_admin_article' => $existingAdminArticle ? $existingAdminArticle->article_cache_id : null, 'quotation_id' => $quotationRequest->id], 'timestamp' => time() * 1000]) . "\n", FILE_APPEND);
        // #endregion
        
        // Process admin articles first (if no admin article exists yet)
        if (!$existingAdminArticle && $adminChildren->count() > 0) {
            // Sort admin articles by priority: 110, 115, 125, 100, 75
            // Use dynamic IDs for priority mapping
            $adminPriority = [
                $admin110Id => 1, // Admin 110 (highest priority)
                $admin115Id => 2, // Admin 115
                $admin125Id => 3, // Admin 125
                $admin100Id => 4, // Admin 100
                $admin75Id => 5,  // Admin 75 (default, lowest priority)
            ];
            $adminChildren = $adminChildren->sortBy(function ($child) use ($adminPriority) {
                return $adminPriority[$child->id] ?? 999;
            });
            
            // #region agent log
            file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode(['sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'C', 'location' => 'QuotationRequestArticle.php:565', 'message' => 'Admin children processing', 'data' => ['admin_children_count' => $adminChildren->count(), 'admin_priority_map' => $adminPriority, 'quotation_id' => $quotationRequest->id], 'timestamp' => time() * 1000]) . "\n", FILE_APPEND);
            // #endregion
            
            foreach ($adminChildren as $child) {
                $childType = $child->pivot->child_type ?? 'optional';
                
                if ($childType === 'conditional') {
                    $conditions = is_string($child->pivot->conditions) 
                        ? json_decode($child->pivot->conditions, true) 
                        : $child->pivot->conditions;
                    
                    // Special handling for Admin 75 (empty conditions = default)
                    $shouldAdd = false;
                    if (empty($conditions)) {
                        // Admin 75 (default) - only add if no other admin article matched
                        $shouldAdd = !$adminArticleAdded;
                    } else {
                        $shouldAdd = $conditionMatcher->matchConditions($conditions, $quotationRequest);
                    }
                    
                    // #region agent log
                    file_put_contents('/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/.cursor/debug.log', json_encode(['sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'E', 'location' => 'QuotationRequestArticle.php:585', 'message' => 'Admin article condition evaluation', 'data' => ['admin_article_id' => $child->id, 'admin_article_name' => $child->article_name, 'conditions' => $conditions, 'should_add' => $shouldAdd, 'pod' => $quotationRequest->pod, 'commodity_type' => $quotationRequest->commodity_type, 'quotation_id' => $quotationRequest->id], 'timestamp' => time() * 1000]) . "\n", FILE_APPEND);
                    // #endregion
                    
                    if ($shouldAdd) {
                        // Check if this admin article already exists
                        $exists = self::where('quotation_request_id', $quotationRequest->id)
                            ->where('article_cache_id', $child->id)
                            ->exists();
                        
                        if (!$exists) {
                            try {
                                // Force quantity = 1 for admin articles
                                $quantity = 1;
                                
                                $created = self::create([
                                    'quotation_request_id' => $quotationRequest->id,
                                    'article_cache_id' => $child->id,
                                    'parent_article_id' => $this->article_cache_id,
                                    'item_type' => 'child',
                                    'quantity' => $quantity,
                                    'unit_type' => $child->pivot->unit_type ?? $child->unit_type ?? 'unit',
                                    'unit_price' => $child->pivot->default_cost_price ?? $child->unit_price,
                                    'selling_price' => $child->getPriceForRole($role ?: 'default'),
                                    'currency' => $child->currency,
                                ]);
                                
                                $adminArticleAdded = true;
                                \Log::info('Admin article added', [
                                    'admin_article_id' => $child->id,
                                    'admin_article_name' => $child->article_name,
                                    'quotation_request_article_id' => $created->id,
                                ]);
                                
                                // Only add one admin article per quotation
                                break;
                            } catch (\Exception $e) {
                                \Log::error('Failed to create admin article', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString(),
                                ]);
                            }
                        } else {
                            $adminArticleAdded = true;
                            break;
                        }
                    }
                }
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
                $conditions = is_string($child->pivot->conditions) 
                    ? json_decode($child->pivot->conditions, true) 
                    : $child->pivot->conditions;
                
                $shouldAdd = $conditionMatcher->matchConditions($conditions, $quotationRequest);
                \Log::info('Child is conditional', [
                    'conditions' => $conditions,
                    'should_add' => $shouldAdd,
                ]);
            } else {
                \Log::info('Child is optional - will not auto-add');
            }
            
            if ($shouldAdd) {
                // Check if child article is not already added
                $exists = self::where('quotation_request_id', $quotationRequest->id)
                    ->where('article_cache_id', $child->id)
                    ->where('parent_article_id', $this->article_cache_id)
                    ->exists();
                
                \Log::info('Checking if child already exists', [
                    'exists' => $exists,
                ]);
                
                if (!$exists) {
                    try {
                        // Ensure quantity is an integer (default_quantity might be decimal)
                        $quantity = (int) ($child->pivot->default_quantity ?? $this->quantity);
                        
                        $created = self::create([
                            'quotation_request_id' => $quotationRequest->id,
                            'article_cache_id' => $child->id,
                            'parent_article_id' => $this->article_cache_id,
                            'item_type' => 'child',
                            'quantity' => $quantity,
                            'unit_type' => $child->pivot->unit_type ?? $child->unit_type ?? 'unit',
                            'unit_price' => $child->pivot->default_cost_price ?? $child->unit_price,
                            'selling_price' => $child->getPriceForRole($role ?: 'default'),
                            'currency' => $child->currency,
                        ]);
                        \Log::info('Child article created successfully', [
                            'quotation_request_article_id' => $created->id,
                        ]);
                    } catch (\Exception $e) {
                        \Log::error('Failed to create child article', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
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
}


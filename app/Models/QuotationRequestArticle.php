<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            
            // Auto-calculate subtotal
            $model->subtotal = $model->quantity * $model->selling_price;
        });

        static::saved(function ($model) {
            // When parent article is added, automatically add children
            if ($model->item_type === 'parent' && $model->articleCache && $model->articleCache->is_parent_article) {
                $model->addChildArticles();
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

    public function articleCache(): BelongsTo
    {
        return $this->belongsTo(RobawsArticleCache::class, 'article_cache_id');
    }

    public function parentArticle(): BelongsTo
    {
        return $this->belongsTo(RobawsArticleCache::class, 'parent_article_id');
    }

    /**
     * Auto-add child articles when parent is selected
     */
    public function addChildArticles(): void
    {
        $children = $this->articleCache->children;
        $quotationRequest = $this->quotationRequest;
        $role = $quotationRequest->customer_role;
        
        $conditionMatcher = app(\App\Services\CompositeItems\ConditionMatcherService::class);
        
        foreach ($children as $child) {
            $childType = $child->pivot->child_type ?? 'optional';
            $shouldAdd = false;
            
            // Determine if child should be added based on child_type
            if ($childType === 'mandatory') {
                // Always add mandatory items
                $shouldAdd = true;
            } elseif ($childType === 'conditional') {
                // Add conditional items only if conditions match
                $conditions = is_string($child->pivot->conditions) 
                    ? json_decode($child->pivot->conditions, true) 
                    : $child->pivot->conditions;
                
                $shouldAdd = $conditionMatcher->matchConditions($conditions, $quotationRequest);
            }
            // Optional items are never auto-added (customer chooses in UI)
            
            if ($shouldAdd) {
                // Check if child article is not already added
                $exists = self::where('quotation_request_id', $quotationRequest->id)
                    ->where('article_cache_id', $child->id)
                    ->where('parent_article_id', $this->article_cache_id)
                    ->exists();
                
                if (!$exists) {
                    self::create([
                        'quotation_request_id' => $quotationRequest->id,
                        'article_cache_id' => $child->id,
                        'parent_article_id' => $this->article_cache_id,
                        'item_type' => 'child',
                        'quantity' => $child->pivot->default_quantity ?? $this->quantity,
                        'unit_type' => $child->pivot->unit_type ?? $child->unit_type ?? 'unit',
                        'unit_price' => $child->pivot->default_cost_price ?? $child->unit_price,
                        'selling_price' => $child->getPriceForRole($role ?: 'default'),
                        'currency' => $child->currency,
                    ]);
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


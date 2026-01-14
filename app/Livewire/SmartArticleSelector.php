<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\QuotationRequest;
use App\Services\SmartArticleSelectionService;
use Illuminate\Support\Collection;

class SmartArticleSelector extends Component
{
    public QuotationRequest $quotation;
    public Collection $suggestedArticles;
    public array $selectedArticles = [];
    public array $matchReasons = [];
    public bool $loading = false;
    public int $minMatchPercentage = 30;
    public int $maxArticles = 10;
    
    // Customer portal specific
    public bool $showPricing = true;
    public bool $isEditable = true;
    public ?int $pricingTierId = null;
    
    protected $listeners = [
        'quotationUpdated' => 'loadSuggestions',
        'refreshSuggestions' => 'loadSuggestions',
        'removeArticle' => 'removeArticle',
    ];
    
    public function mount(QuotationRequest $quotation, bool $showPricing = true, bool $isEditable = true)
    {
        $this->quotation = $quotation;
        $this->showPricing = $showPricing;
        $this->isEditable = $isEditable;
        
        // Get pricing tier: User tier → Quotation tier → Default to Tier C
        $this->pricingTierId = auth()->user()?->pricing_tier_id 
            ?? $quotation->pricing_tier_id 
            ?? \App\Models\PricingTier::where('code', 'C')->first()?->id;
        
        $this->suggestedArticles = collect();
        $this->loadSuggestions();
    }
    
    public function loadSuggestions()
    {
        $this->loading = true;
        
        // Always refresh quotation to ensure latest data (including commodity_type and commodityItems)
        // This is especially important when schedule is selected before commodity_type
        // and when commodity items are auto-saved in detailed quote mode
        $this->quotation = $this->quotation->fresh(['selectedSchedule.carrier', 'commodityItems']);
        
        try {
            $service = app(SmartArticleSelectionService::class);

            $suggestions = $service->getTopSuggestions(
                $this->quotation, 
                $this->maxArticles, 
                $this->minMatchPercentage
            );
            
            $this->suggestedArticles = $suggestions;
            
            // Update match reasons for display
            $this->matchReasons = $suggestions->mapWithKeys(function ($suggestion) {
                return [
                    $suggestion['article']->id => $suggestion['match_reasons']
                ];
            })->toArray();
            
        } catch (\Exception $e) {
            \Log::error('Failed to load smart article suggestions', [
                'quotation_id' => $this->quotation->id,
                'error' => $e->getMessage()
            ]);
            
            $this->suggestedArticles = collect();
        }
        
        $this->loading = false;
    }
    
    public function getTierPrice($article)
    {
        // Always return a price - default to Tier C if no tier
        $tier = \App\Models\PricingTier::find($this->pricingTierId);
        
        if (!$tier) {
            // Fallback to Tier C if tier not found
            $tier = \App\Models\PricingTier::where('code', 'C')->first();
        }
        
        if (!$tier) {
            // Ultimate fallback: base price
            return $article->unit_price;
        }
        
        return $tier->calculateSellingPrice($article->unit_price);
    }
    
    public function selectArticle($articleId)
    {
        if (!$this->isEditable) {
            return; // Prevent selection on approved quotations
        }
        
        // Allow same article to be added multiple times
        // This enables adding the same article for different commodity items
        // Each addition creates a separate QuotationRequestArticle record
        
        $article = \App\Models\RobawsArticleCache::find($articleId);
        if (!$article) {
            return;
        }
        
        // Ensure pricing tier is set on quotation if we have one
        if ($this->pricingTierId && !$this->quotation->pricing_tier_id) {
            $this->quotation->pricing_tier_id = $this->pricingTierId;
            $this->quotation->save();
        }
        
        // Use addArticle() which creates QuotationRequestArticle model
        // This triggers the boot() method which automatically adds child articles
        $quotationRequestArticle = $this->quotation->addArticle($article, 1);
        
        // Override selling price with tier price if we calculated one
        $tierPrice = $this->getTierPrice($article);
        if ($tierPrice && $tierPrice != $quotationRequestArticle->selling_price) {
            $quotationRequestArticle->selling_price = $tierPrice;
            $quotationRequestArticle->subtotal = $tierPrice * $quotationRequestArticle->quantity;
            $quotationRequestArticle->save();
        }
        
        // Update selected articles array
        if (!in_array($articleId, $this->selectedArticles)) {
            $this->selectedArticles[] = $articleId;
        }
        
        // Refresh quotation to get updated articles (including children)
        $this->quotation = $this->quotation->fresh(['articles']);
        
        // Emit to parent
        $this->dispatch('articleAdded', articleId: $articleId);
    }
    
    public function removeArticle($articleId)
    {
        if (!$this->isEditable) {
            return; // Prevent removal on approved quotations
        }
        
        $this->selectedArticles = array_filter($this->selectedArticles, fn($id) => $id !== $articleId);
        
        // Find and delete the QuotationRequestArticle model
        // This will trigger the deleted event which automatically removes child articles
        $quotationRequestArticle = \App\Models\QuotationRequestArticle::where('quotation_request_id', $this->quotation->id)
            ->where('article_cache_id', $articleId)
            ->first();
        
        if ($quotationRequestArticle) {
            $quotationRequestArticle->delete(); // This triggers deleted event and removes children
        } else {
            // Fallback: if model not found, use detach (shouldn't happen, but safe fallback)
            $this->quotation->articles()->detach($articleId);
        }
        
        // Recalculate quotation totals (already done in deleted event, but ensure it's done)
        $this->quotation->calculateTotals();
        $this->quotation->save();
        
        // Refresh quotation
        $this->quotation = $this->quotation->fresh(['articles']);
        
        // Emit to parent
        $this->dispatch('articleRemoved', articleId: $articleId);
    }
    
    public function updateMinMatchPercentage($percentage)
    {
        $this->minMatchPercentage = (int) $percentage;
        $this->loadSuggestions();
    }
    
    public function updateMaxArticles($max)
    {
        $this->maxArticles = (int) $max;
        $this->loadSuggestions();
    }
    
    public function getMatchReasonText($articleId): string
    {
        return $this->matchReasons[$articleId] ?? '';
    }
    
    public function getConfidenceColor($confidence): string
    {
        // Since we always return 100% matches, confidence is always 'high'
        return match ($confidence) {
            'high' => 'text-green-600',
            'medium' => 'text-yellow-600',
            'low' => 'text-orange-600',
            default => 'text-green-600' // Default to green for high confidence
        };
    }
    
    public function getConfidenceLabel($confidence): string
    {
        // Since we always return 100% matches, confidence is always 'high'
        return match ($confidence) {
            'high' => 'Excellent',
            'medium' => 'Good',
            'low' => 'Fair',
            default => 'Excellent' // Default to Excellent for high confidence
        };
    }
    
    /**
     * Check if an article is a mandatory child (cannot be removed)
     */
    public function isMandatoryChild($articleId): bool
    {
        $quotationArticle = \App\Models\QuotationRequestArticle::where('quotation_request_id', $this->quotation->id)
            ->where('article_cache_id', $articleId)
            ->first();
        
        return $quotationArticle ? $quotationArticle->isMandatoryChild() : false;
    }
    
    public function render()
    {
        return view('livewire.smart-article-selector');
    }
}

<?php

namespace App\Http\Livewire;

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
        
        if (!in_array($articleId, $this->selectedArticles)) {
            $this->selectedArticles[] = $articleId;
            
            $article = \App\Models\RobawsArticleCache::find($articleId);
            $tierPrice = $this->getTierPrice($article);
            
            // Attach with tier price and metadata
            $this->quotation->articles()->syncWithoutDetaching([
                $articleId => [
                    'quantity' => 1,
                    'unit_price' => $tierPrice ?? $article->unit_price,
                    'unit_type' => $article->unit_type,
                    'currency' => $article->currency,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            ]);
            
            // Recalculate quotation totals
            $this->quotation->calculateTotals();
            $this->quotation->save();
            
            // Refresh quotation
            $this->quotation = $this->quotation->fresh();
            
            // Emit to parent
            $this->dispatch('articleAdded', articleId: $articleId);
        }
    }
    
    public function removeArticle($articleId)
    {
        if (!$this->isEditable) {
            return; // Prevent removal on approved quotations
        }
        
        $this->selectedArticles = array_filter($this->selectedArticles, fn($id) => $id !== $articleId);
        
        // Detach from quotation
        $this->quotation->articles()->detach($articleId);
        
        // Recalculate quotation totals
        $this->quotation->calculateTotals();
        $this->quotation->save();
        
        // Refresh quotation
        $this->quotation = $this->quotation->fresh();
        
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
        return match (true) {
            $confidence >= 80 => 'text-green-600',
            $confidence >= 60 => 'text-yellow-600',
            $confidence >= 40 => 'text-orange-600',
            default => 'text-red-600'
        };
    }
    
    public function getConfidenceLabel($confidence): string
    {
        return match (true) {
            $confidence >= 80 => 'Excellent',
            $confidence >= 60 => 'Good',
            $confidence >= 40 => 'Fair',
            default => 'Poor'
        };
    }
    
    public function render()
    {
        return view('livewire.smart-article-selector');
    }
}

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
    
    protected $listeners = [
        'quotationUpdated' => 'loadSuggestions',
        'refreshSuggestions' => 'loadSuggestions'
    ];
    
    public function mount(QuotationRequest $quotation)
    {
        $this->quotation = $quotation;
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
    
    public function selectArticle($articleId)
    {
        if (!in_array($articleId, $this->selectedArticles)) {
            $this->selectedArticles[] = $articleId;
            
            // Attach to quotation
            $this->quotation->articles()->syncWithoutDetaching([$articleId]);
            
            // Emit event for parent component
            $this->emit('articleSelected', $articleId);
        }
    }
    
    public function removeArticle($articleId)
    {
        $this->selectedArticles = array_filter($this->selectedArticles, fn($id) => $id !== $articleId);
        
        // Detach from quotation
        $this->quotation->articles()->detach($articleId);
        
        // Emit event for parent component
        $this->emit('articleRemoved', $articleId);
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

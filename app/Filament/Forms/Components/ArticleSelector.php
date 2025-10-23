<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;
use App\Models\QuotationRequest;
use App\Services\SmartArticleSelectionService;

class ArticleSelector extends Field
{
    protected string $view = 'filament.forms.components.article-selector';
    
    protected mixed $serviceType = null;
    protected mixed $customerType = null;
    protected mixed $carrierCode = null;
    protected mixed $quotationId = null;
    
    public function serviceType($serviceType): static
    {
        $this->serviceType = $serviceType;
        
        return $this;
    }
    
    public function customerType($customerType): static
    {
        $this->customerType = $customerType;
        
        return $this;
    }
    
    public function carrierCode($carrierCode): static
    {
        $this->carrierCode = $carrierCode;
        
        return $this;
    }
    
    public function quotationId($quotationId): static
    {
        $this->quotationId = $quotationId;
        
        return $this;
    }
    
    public function getServiceType(): ?string
    {
        return $this->evaluate($this->serviceType);
    }
    
    public function getCustomerType(): ?string
    {
        return $this->evaluate($this->customerType);
    }
    
    public function getCarrierCode(): ?string
    {
        return $this->evaluate($this->carrierCode);
    }
    
    public function getQuotationId(): ?int
    {
        return $this->evaluate($this->quotationId);
    }
    
    /**
     * Get smart article suggestions for the current quotation
     */
    public function getSmartSuggestions(): array
    {
        $quotationId = $this->getQuotationId();
        
        if (!$quotationId) {
            return [];
        }
        
        try {
            $quotation = QuotationRequest::find($quotationId);
            if (!$quotation) {
                return [];
            }
            
            $service = app(SmartArticleSelectionService::class);
            $suggestions = $service->getTopSuggestions($quotation, 10, 30); // Top 10 with min 30% match
            
            return $suggestions->map(function ($suggestion) {
                return [
                    'id' => $suggestion['article']->id,
                    'robaws_article_id' => $suggestion['article']->robaws_article_id,
                    'article_name' => $suggestion['article']->article_name,
                    'description' => $suggestion['article']->description,
                    'article_code' => $suggestion['article']->article_code,
                    'unit_price' => $suggestion['article']->unit_price,
                    'unit_type' => $suggestion['article']->unit_type,
                    'currency' => $suggestion['article']->currency,
                    'is_parent_article' => $suggestion['article']->is_parent_article,
                    'match_score' => $suggestion['match_score'],
                    'match_percentage' => $suggestion['match_percentage'],
                    'match_reasons' => $suggestion['match_reasons'],
                    'confidence' => $suggestion['confidence'],
                    'is_smart_suggestion' => true,
                ];
            })->toArray();
            
        } catch (\Exception $e) {
            \Log::error('Failed to get smart article suggestions', [
                'quotation_id' => $quotationId,
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
}


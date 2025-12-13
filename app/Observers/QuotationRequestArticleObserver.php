<?php

namespace App\Observers;

use App\Models\QuotationRequestArticle;
use App\Services\Pricing\VatResolverInterface;

class QuotationRequestArticleObserver
{
    public function __construct(
        private readonly VatResolverInterface $vatResolver,
    ) {}
    
    /**
     * Ensure each article line has a vat_code set on save
     */
    public function saving(QuotationRequestArticle $line): void
    {
        if (!$line->relationLoaded('quotationRequest')) {
            $line->load('quotationRequest');
        }
        
        $quotation = $line->quotationRequest;
        if (!$quotation) {
            return;
        }
        
        // Ensure quotation has project_vat_code
        if (!$quotation->project_vat_code) {
            $quotation->project_vat_code = $this->vatResolver->determineProjectVatCode($quotation);
            $quotation->saveQuietly();
        }
        
        // Set vat_code for this line
        $line->vat_code = $this->vatResolver->determineLineVatCode(
            $line,
            $quotation->project_vat_code
        );
    }
}


<?php

namespace App\Services\Pricing;

use App\Models\QuotationRequest;
use App\Models\QuotationRequestArticle;

class QuotationVatService
{
    public function __construct(
        private readonly VatResolverInterface $vatResolver,
    ) {}
    
    /**
     * Recompute VAT for the quotation and all its lines.
     */
    public function recalculateVatForQuotation(QuotationRequest $quotation): void
    {
        // Refresh to ensure we have latest data
        $quotation->refresh();
        
        $projectVatCode = $this->vatResolver->determineProjectVatCode($quotation);
        $quotation->project_vat_code = $projectVatCode;
        $quotation->saveQuietly();
        
        // Update all articles with the correct VAT code
        QuotationRequestArticle::where('quotation_request_id', $quotation->id)
            ->get()
            ->each(function ($article) use ($projectVatCode) {
                // Reload article to ensure we have latest data
                $article->refresh();
                
                $vatCode = $this->vatResolver->determineLineVatCode($article, $projectVatCode);
                
                // Only update if different to avoid unnecessary saves
                if ($article->vat_code !== $vatCode) {
                    $article->vat_code = $vatCode;
                    $article->saveQuietly();
                }
            });
    }
}


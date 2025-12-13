<?php

namespace App\Services\Pricing;

use App\Models\QuotationRequest;
use App\Models\QuotationRequestArticle;

class VatResolver implements VatResolverInterface
{
    public function __construct(
        private readonly EuCountryChecker $euChecker,
    ) {}

    public function determineProjectVatCode(QuotationRequest $quotation): string
    {
        $originCountryIso = $this->euChecker->getCountryIsoFromPortString($quotation->pol);
        $destinationCountryIso = $this->euChecker->getCountryIsoFromPortString($quotation->pod);
        
        $customerCountryIso = null;
        if ($quotation->robaws_client_id && $quotation->relationLoaded('customer')) {
            $customerCountryIso = $quotation->customer?->country_code;
        } elseif ($quotation->robaws_client_id) {
            $customer = \App\Models\RobawsCustomerCache::where('robaws_client_id', $quotation->robaws_client_id)->first();
            $customerCountryIso = $customer?->country_code;
        }
        
        $isExport = $this->isExport($originCountryIso, $destinationCountryIso);
        $isImport = $this->isImport($originCountryIso, $destinationCountryIso);
        
        // BE → BE: 21% VF
        if ($originCountryIso === 'BE' && $destinationCountryIso === 'BE') {
            return '21% VF';
        }
        
        // BE → EU (other): intracommunautaire levering VF
        if ($originCountryIso === 'BE' && $destinationCountryIso && $this->euChecker->isEuCountry($destinationCountryIso)) {
            return 'intracommunautaire levering VF';
        }
        
        // Export (BE → non-EU): vrijgesteld VF
        if ($isExport) {
            return 'vrijgesteld VF';
        }
        
        // Import (non-BE → BE): 21% VF
        if ($isImport) {
            return '21% VF';
        }
        
        // Default: 21% VF
        return '21% VF';
    }
    
    public function determineLineVatCode(QuotationRequestArticle $line, string $projectVatCode): string
    {
        if (!$line->relationLoaded('articleCache')) {
            $line->load('articleCache');
        }
        
        $article = $line->articleCache;
        if (!$article) {
            return $projectVatCode;
        }
        
        $articleType = $article->article_type ?? null;
        
        // Import services (trucking, warehouse, customs, import): Always 21% VF
        if ($this->isImportService($articleType)) {
            return '21% VF';
        }
        
        // Pre-export services: Always vrijgesteld VF
        if ($this->isPreExportService($articleType)) {
            return 'vrijgesteld VF';
        }
        
        // Other articles: Use project VAT code
        return $projectVatCode;
    }
    
    private function isExport(?string $origin, ?string $destination): bool
    {
        if ($origin !== 'BE') {
            return false;
        }
        
        if (!$destination) {
            return false;
        }
        
        // Handle special case where we know it's non-EU but don't have ISO code
        if ($destination === 'NON_EU') {
            return true;
        }
        
        return !$this->euChecker->isEuCountry($destination);
    }
    
    private function isImport(?string $origin, ?string $destination): bool
    {
        return $origin && $origin !== 'BE' && $destination === 'BE';
    }
    
    private function isImportService(?string $articleType): bool
    {
        if (!$articleType) {
            return false;
        }
        
        $importKeywords = ['trucking', 'warehouse', 'customs', 'import'];
        return in_array(strtolower($articleType), $importKeywords) ||
               str_contains(strtolower($articleType), 'import');
    }
    
    private function isPreExportService(?string $articleType): bool
    {
        if (!$articleType) {
            return false;
        }
        
        $preExportKeywords = ['pre-export', 'pre-export', 'preparation'];
        return in_array(strtolower($articleType), $preExportKeywords) ||
               str_contains(strtolower($articleType), 'pre-export');
    }
}


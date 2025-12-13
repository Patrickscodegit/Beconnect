<?php

namespace App\Services\Pricing;

use App\Models\QuotationRequest;
use App\Models\QuotationRequestArticle;

interface VatResolverInterface
{
    /**
     * Determines the Robaws VAT code for an entire quotation
     * based on route + customer (BE / EU / export / import / cross-trade).
     */
    public function determineProjectVatCode(QuotationRequest $quotation): string;

    /**
     * Determines the Robaws VAT code for one article line,
     * taking into account article type, projectVatCode and import/export rules.
     */
    public function determineLineVatCode(QuotationRequestArticle $line, string $projectVatCode): string;
}


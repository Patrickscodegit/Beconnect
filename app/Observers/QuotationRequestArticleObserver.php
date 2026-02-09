<?php

namespace App\Observers;

use App\Models\QuotationRequestArticle;
use App\Services\Pricing\VatResolverInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
        // Check if column exists to prevent errors if migration hasn't run
        if (!$this->columnExists('quotation_request_articles', 'vat_code')) {
            Log::warning('QuotationRequestArticleObserver::saving - vat_code column does not exist, skipping VAT code assignment');
            return;
        }

        try {
            if (!$line->relationLoaded('quotationRequest')) {
                $line->load('quotationRequest');
            }
            
            $quotation = $line->quotationRequest;
            if (!$quotation) {
                return;
            }
            
            // Check if quotation has project_vat_code column
            if (!$this->columnExists('quotation_requests', 'project_vat_code')) {
                Log::warning('QuotationRequestArticleObserver::saving - project_vat_code column does not exist on quotation, skipping VAT code assignment');
                return;
            }
            
            // Ensure quotation has project_vat_code
            if (!$quotation->project_vat_code) {
                try {
                    $quotation->project_vat_code = $this->vatResolver->determineProjectVatCode($quotation);
                    $quotation->saveQuietly();
                } catch (\Exception $e) {
                    Log::error('QuotationRequestArticleObserver::saving - Error setting quotation VAT code', [
                        'quotation_id' => $quotation->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with default
                    $quotation->project_vat_code = $quotation->project_vat_code ?? '21% VF';
                }
            }
            
            // Set vat_code for this line
            try {
                $line->vat_code = $this->vatResolver->determineLineVatCode(
                    $line,
                    $quotation->project_vat_code ?? '21% VF'
                );
            } catch (\Exception $e) {
                Log::error('QuotationRequestArticleObserver::saving - Error setting line VAT code', [
                    'article_id' => $line->id,
                    'quotation_id' => $quotation->id,
                    'error' => $e->getMessage(),
                ]);
                // Fallback to project VAT code
                $line->vat_code = $quotation->project_vat_code ?? '21% VF';
            }
        } catch (\Exception $e) {
            Log::error('QuotationRequestArticleObserver::saving - Unexpected error', [
                'article_id' => $line->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Don't throw - allow article to save even if VAT code assignment fails
        }
    }

    /**
     * Check if a column exists in a table
     */
    protected function columnExists(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Exception $e) {
            Log::warning('QuotationRequestArticleObserver::columnExists - Error checking column', [
                'table' => $table,
                'column' => $column,
                'error' => $e->getMessage(),
            ]);
            // If we can't check, assume it exists to avoid breaking saves
            return true;
        }
    }
}


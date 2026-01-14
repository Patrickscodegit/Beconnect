<?php

namespace App\Services\Pricing;

use App\Models\QuotationRequest;
use App\Models\QuotationRequestArticle;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
        // Check if columns exist to prevent errors if migration hasn't run
        if (!$this->columnExists('quotation_requests', 'project_vat_code')) {
            Log::warning('QuotationVatService::recalculateVatForQuotation - project_vat_code column does not exist, skipping VAT recalculation');
            return;
        }

        try {
            // Refresh to ensure we have latest data
            $quotation->refresh();
            
            $projectVatCode = $this->vatResolver->determineProjectVatCode($quotation);
            $quotation->project_vat_code = $projectVatCode;
            $quotation->saveQuietly();
            
            // Recalculate totals with the new VAT code
            $quotation->calculateTotals();
            
            // Check if vat_code column exists on articles table
            if ($this->columnExists('quotation_request_articles', 'vat_code')) {
                // Update all articles with the correct VAT code
                QuotationRequestArticle::where('quotation_request_id', $quotation->id)
                    ->get()
                    ->each(function ($article) use ($projectVatCode) {
                        try {
                            // Reload article to ensure we have latest data
                            $article->refresh();
                            
                            $vatCode = $this->vatResolver->determineLineVatCode($article, $projectVatCode);
                            
                            // Only update if different to avoid unnecessary saves
                            if ($article->vat_code !== $vatCode) {
                                $article->vat_code = $vatCode;
                                $article->saveQuietly();
                            }
                        } catch (\Exception $e) {
                            Log::error('QuotationVatService::recalculateVatForQuotation - Error updating article VAT code', [
                                'article_id' => $article->id,
                                'quotation_id' => $quotation->id,
                                'error' => $e->getMessage(),
                            ]);
                            // Continue with other articles
                        }
                    });
            }
        } catch (\Exception $e) {
            Log::error('QuotationVatService::recalculateVatForQuotation - Error recalculating VAT', [
                'quotation_id' => $quotation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Don't throw - allow quotation to continue even if VAT calculation fails
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
            Log::warning('QuotationVatService::columnExists - Error checking column', [
                'table' => $table,
                'column' => $column,
                'error' => $e->getMessage(),
            ]);
            // If we can't check, assume it exists to avoid breaking saves
            return true;
        }
    }
}


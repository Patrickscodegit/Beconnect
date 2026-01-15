<?php

namespace Tests\Feature\Quotation;

use App\Models\QuotationRequest;
use App\Models\QuotationRequestArticle;
use App\Models\RobawsArticleCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationArticleQuantityPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private function createQuotation(): QuotationRequest
    {
        return QuotationRequest::create([
            'source' => 'intake',
            'requester_type' => 'admin',
            'contact_email' => 'admin@belgaco.com',
            'contact_name' => 'Test Customer',
            'contact_company' => 'Test Company',
            'contact_phone' => '+1234567890',
            'trade_direction' => 'export',
            'robaws_sync_status' => 'pending',
            'pricing_currency' => 'EUR',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'routing' => ['por' => null, 'pol' => 'Antwerp', 'pod' => 'Lagos', 'fdest' => null],
            'cargo_details' => [],
            'cargo_description' => 'Test cargo',
            'commodity_type' => 'car',
            'status' => 'pending',
        ]);
    }

    private function createArticleCache(array $overrides = []): RobawsArticleCache
    {
        return RobawsArticleCache::create(array_merge([
            'robaws_article_id' => 'RA-' . uniqid(),
            'article_name' => 'LM/CBM Article',
            'category' => 'test',
            'min_quantity' => 1,
            'max_quantity' => 1,
            'currency' => 'EUR',
            'last_synced_at' => now(),
        ], $overrides));
    }

    /** @test */
    public function it_preserves_fractional_lm_quantity_and_subtotal_precision(): void
    {
        $quotation = $this->createQuotation();
        $articleCache = $this->createArticleCache(['unit_type' => 'LM']);

        $article = QuotationRequestArticle::create([
            'quotation_request_id' => $quotation->id,
            'article_cache_id' => $articleCache->id,
            'item_type' => 'standalone',
            'quantity' => 1.25,
            'unit_type' => 'LM',
            'unit_price' => 100,
            'selling_price' => 100,
            'subtotal' => 0,
            'currency' => 'EUR',
        ]);

        $article->refresh();

        $this->assertSame('1.2500', $article->quantity);
        $this->assertSame('125.00', $article->subtotal);
    }

    /** @test */
    public function it_preserves_fractional_cbm_quantity_and_subtotal_precision(): void
    {
        $quotation = $this->createQuotation();
        $articleCache = $this->createArticleCache(['unit_type' => 'CBM']);

        $article = QuotationRequestArticle::create([
            'quotation_request_id' => $quotation->id,
            'article_cache_id' => $articleCache->id,
            'item_type' => 'standalone',
            'quantity' => 2.3456,
            'unit_type' => 'CBM',
            'unit_price' => 80,
            'selling_price' => 80,
            'subtotal' => 0,
            'currency' => 'EUR',
        ]);

        $article->refresh();

        $this->assertSame('2.3456', $article->quantity);
        $this->assertSame('187.65', $article->subtotal);
    }
}

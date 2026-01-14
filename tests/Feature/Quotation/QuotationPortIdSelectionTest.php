<?php

namespace Tests\Feature\Quotation;

use App\Models\Port;
use App\Models\QuotationCommodityItem;
use App\Models\QuotationRequest;
use App\Models\QuotationRequestArticle;
use App\Models\RobawsArticleCache;
use App\Services\CarrierRules\CarrierRuleIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationPortIdSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function createQuotation(array $overrides = []): QuotationRequest
    {
        return QuotationRequest::create(array_merge([
            'source' => 'intake',
            'requester_type' => 'admin',
            'contact_email' => 'admin@belgaco.com',
            'contact_name' => 'Test Customer',
            'contact_phone' => '+1234567890',
            'trade_direction' => 'export',
            'robaws_sync_status' => 'pending',
            'pricing_currency' => 'EUR',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'simple_service_type' => 'SEA_RORO',
            'pol' => 'Unknown POL',
            'pod' => 'Unknown POD',
            'routing' => ['por' => null, 'pol' => 'Unknown POL', 'pod' => 'Unknown POD', 'fdest' => null],
            'cargo_details' => [],
            'cargo_description' => 'Test cargo',
            'status' => 'pending',
        ], $overrides));
    }

    public function test_scope_prefers_port_ids_over_string_matching(): void
    {
        $pol = Port::create([
            'name' => 'Antwerp',
            'code' => 'ANR',
            'country' => 'Belgium',
            'port_category' => 'SEA_PORT',
            'is_active' => true,
        ]);

        $pod = Port::create([
            'name' => 'Abidjan',
            'code' => 'ABJ',
            'country' => "Cote d'Ivoire",
            'port_category' => 'SEA_PORT',
            'is_active' => true,
        ]);

        $article = RobawsArticleCache::create([
            'robaws_article_id' => 1001,
            'article_code' => 'TEST-001',
            'article_name' => 'Test Article',
            'category' => 'freight',
            'is_active' => true,
            'is_parent_item' => true,
            'is_parent_article' => true,
            'last_synced_at' => now(),
            'service_type' => 'RORO_EXPORT',
            'transport_mode' => 'RORO',
            'pol' => 'Different POL String',
            'pod' => 'Different POD String',
            'pol_port_id' => $pol->id,
            'pod_port_id' => $pod->id,
        ]);

        $quotation = $this->createQuotation([
            'pol' => $pol->formatFull(),
            'pod' => $pod->formatFull(),
            'routing' => ['por' => null, 'pol' => $pol->formatFull(), 'pod' => $pod->formatFull(), 'fdest' => null],
            'pol_port_id' => $pol->id,
            'pod_port_id' => $pod->id,
        ]);

        $results = RobawsArticleCache::forQuotationContext($quotation)->pluck('id')->toArray();

        $this->assertContains($article->id, $results);
    }

    public function test_remove_non_matching_articles_skips_carrier_rule_articles(): void
    {
        $quotation = $this->createQuotation();

        $commodity = QuotationCommodityItem::create([
            'quotation_request_id' => $quotation->id,
            'line_number' => 1,
            'commodity_type' => 'vehicles',
            'category' => 'car',
            'quantity' => 1,
        ]);

        $article = RobawsArticleCache::create([
            'robaws_article_id' => 2001,
            'article_code' => 'SUR-001',
            'article_name' => 'Surcharge',
            'category' => 'surcharge',
            'is_active' => true,
            'is_parent_item' => true,
            'is_parent_article' => true,
            'last_synced_at' => now(),
            'service_type' => 'RORO_EXPORT',
            'transport_mode' => 'RORO',
        ]);

        $quotationArticle = QuotationRequestArticle::create([
            'quotation_request_id' => $quotation->id,
            'article_cache_id' => $article->id,
            'item_type' => 'standalone',
            'quantity' => 1,
            'unit_type' => 'unit',
            'unit_price' => 100,
            'selling_price' => 100,
            'subtotal' => 100,
            'carrier_rule_applied' => true,
            'carrier_rule_event_code' => 'TOWING',
            'carrier_rule_commodity_item_id' => $commodity->id,
        ]);

        $service = app(CarrierRuleIntegrationService::class);
        $service->removeNonMatchingArticles($quotation->fresh(['commodityItems']));

        $this->assertDatabaseHas('quotation_request_articles', [
            'id' => $quotationArticle->id,
        ]);
    }
}

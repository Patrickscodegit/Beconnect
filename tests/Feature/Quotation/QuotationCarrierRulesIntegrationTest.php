<?php

namespace Tests\Feature\Quotation;

use App\Models\CarrierArticleMapping;
use App\Models\CarrierCategoryGroup;
use App\Models\CarrierCategoryGroupMember;
use App\Models\CarrierSurchargeRule;
use App\Models\Port;
use App\Models\QuotationCommodityItem;
use App\Models\QuotationRequest;
use App\Models\QuotationRequestArticle;
use App\Models\RobawsArticleCache;
use App\Models\ShippingCarrier;
use App\Models\ShippingSchedule;
use App\Services\CarrierRules\CarrierRuleIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationCarrierRulesIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function createCarrier(): ShippingCarrier
    {
        return ShippingCarrier::create([
            'name' => 'Test Carrier',
            'code' => 'TEST',
            'is_active' => true,
        ]);
    }

    private function createPort(string $name, string $code): Port
    {
        return Port::create([
            'name' => $name,
            'code' => $code,
            'country' => 'Belgium',
            'port_category' => 'SEA_PORT',
            'is_active' => true,
        ]);
    }

    private function createSchedule(ShippingCarrier $carrier, Port $pol, Port $pod): ShippingSchedule
    {
        return ShippingSchedule::create([
            'carrier_id' => $carrier->id,
            'pol_id' => $pol->id,
            'pod_id' => $pod->id,
            'service_name' => 'Test Service',
            'is_active' => true,
        ]);
    }

    private function createQuotation(array $overrides = []): QuotationRequest
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
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'routing' => ['por' => null, 'pol' => 'Antwerp', 'pod' => 'Lagos', 'fdest' => null],
            'cargo_details' => [],
            'cargo_description' => 'Test cargo',
            'status' => 'pending',
        ], $overrides));
    }

    /** @test */
    public function it_selects_mapped_article_by_category_group(): void
    {
        $carrier = $this->createCarrier();
        $pol = $this->createPort('Antwerp', 'ANR');
        $pod = $this->createPort('Abidjan', 'ABJ');
        $schedule = $this->createSchedule($carrier, $pol, $pod);

        $categoryGroup = CarrierCategoryGroup::create([
            'carrier_id' => $carrier->id,
            'code' => 'CARS',
            'display_name' => 'Cars',
            'is_active' => true,
        ]);

        CarrierCategoryGroupMember::create([
            'carrier_category_group_id' => $categoryGroup->id,
            'vehicle_category' => 'car',
            'is_active' => true,
        ]);

        $article = RobawsArticleCache::create([
            'robaws_article_id' => 'MAP-001',
            'article_name' => 'Mapped Article',
            'category' => 'freight',
            'is_active' => true,
            'is_parent_item' => true,
            'is_parent_article' => true,
            'last_synced_at' => now(),
            'service_type' => 'RORO_EXPORT',
            'transport_mode' => 'RORO',
            'pol_port_id' => $pol->id,
            'pod_port_id' => $pod->id,
        ]);

        CarrierArticleMapping::create([
            'carrier_id' => $carrier->id,
            'article_id' => $article->id,
            'category_group_ids' => [$categoryGroup->id],
            'port_ids' => [$pod->id],
            'is_active' => true,
        ]);

        $quotation = $this->createQuotation([
            'pol' => $pol->formatFull(),
            'pod' => $pod->formatFull(),
            'pol_port_id' => $pol->id,
            'pod_port_id' => $pod->id,
            'selected_schedule_id' => $schedule->id,
            'routing' => ['por' => null, 'pol' => $pol->formatFull(), 'pod' => $pod->formatFull(), 'fdest' => null],
        ]);

        QuotationCommodityItem::create([
            'quotation_request_id' => $quotation->id,
            'line_number' => 1,
            'commodity_type' => 'vehicles',
            'category' => 'car',
            'quantity' => 1,
        ]);

        $results = RobawsArticleCache::forQuotationContext($quotation->fresh(['commodityItems', 'selectedSchedule.carrier']))
            ->pluck('id')
            ->toArray();

        $this->assertContains($article->id, $results);
    }

    /** @test */
    public function it_resolves_port_ids_from_pol_pod_strings(): void
    {
        $pol = $this->createPort('Antwerp', 'ANR');
        $pod = $this->createPort('Abidjan', 'ABJ');

        $article = RobawsArticleCache::create([
            'robaws_article_id' => 'PORT-001',
            'article_name' => 'Port Match Article',
            'category' => 'freight',
            'is_active' => true,
            'is_parent_item' => true,
            'is_parent_article' => true,
            'last_synced_at' => now(),
            'service_type' => 'RORO_EXPORT',
            'transport_mode' => 'RORO',
            'pol_port_id' => $pol->id,
            'pod_port_id' => $pod->id,
        ]);

        $quotation = $this->createQuotation([
            'pol' => $pol->formatFull(),
            'pod' => $pod->formatFull(),
            'pol_port_id' => null,
            'pod_port_id' => null,
            'routing' => ['por' => null, 'pol' => $pol->formatFull(), 'pod' => $pod->formatFull(), 'fdest' => null],
        ]);

        $results = RobawsArticleCache::forQuotationContext($quotation)->pluck('id')->toArray();

        $this->assertContains($article->id, $results);
    }

    /** @test */
    public function it_calculates_lm_quantity_with_schedule_context(): void
    {
        $carrier = $this->createCarrier();
        $pol = $this->createPort('Antwerp', 'ANR');
        $pod = $this->createPort('Abidjan', 'ABJ');
        $schedule = $this->createSchedule($carrier, $pol, $pod);

        $quotation = $this->createQuotation([
            'selected_schedule_id' => $schedule->id,
            'pol' => $pol->formatFull(),
            'pod' => $pod->formatFull(),
            'pol_port_id' => $pol->id,
            'pod_port_id' => $pod->id,
        ]);

        QuotationCommodityItem::create([
            'quotation_request_id' => $quotation->id,
            'line_number' => 1,
            'commodity_type' => 'vehicles',
            'category' => 'car',
            'quantity' => 1,
            'length_cm' => 500,
            'width_cm' => 200,
        ]);

        $article = RobawsArticleCache::create([
            'robaws_article_id' => 'LM-001',
            'article_name' => 'LM Article',
            'category' => 'freight',
            'is_active' => true,
            'is_parent_item' => true,
            'is_parent_article' => true,
            'last_synced_at' => now(),
            'unit_type' => 'LM',
            'unit_price' => 100,
            'currency' => 'EUR',
            'service_type' => 'RORO_EXPORT',
            'transport_mode' => 'RORO',
        ]);

        $quotationArticle = QuotationRequestArticle::create([
            'quotation_request_id' => $quotation->id,
            'article_cache_id' => $article->id,
            'item_type' => 'standalone',
            'quantity' => 1,
            'unit_type' => 'LM',
            'unit_price' => 100,
            'selling_price' => 100,
            'subtotal' => 0,
        ]);

        $quotationArticle->refresh();

        $this->assertSame('5.0000', $quotationArticle->quantity);
        $this->assertSame('500.00', $quotationArticle->subtotal);
    }

    /** @test */
    public function it_adds_surcharge_article_from_rule(): void
    {
        $carrier = $this->createCarrier();
        $pol = $this->createPort('Antwerp', 'ANR');
        $pod = $this->createPort('Abidjan', 'ABJ');
        $schedule = $this->createSchedule($carrier, $pol, $pod);

        $quotation = $this->createQuotation([
            'selected_schedule_id' => $schedule->id,
            'pol' => $pol->formatFull(),
            'pod' => $pod->formatFull(),
            'pol_port_id' => $pol->id,
            'pod_port_id' => $pod->id,
        ]);

        $item = QuotationCommodityItem::create([
            'quotation_request_id' => $quotation->id,
            'line_number' => 1,
            'commodity_type' => 'vehicles',
            'category' => 'car',
            'quantity' => 1,
        ]);

        $surchargeArticle = RobawsArticleCache::create([
            'robaws_article_id' => 'SUR-001',
            'article_name' => 'Test Surcharge',
            'category' => 'surcharge',
            'is_active' => true,
            'is_parent_item' => true,
            'is_parent_article' => true,
            'last_synced_at' => now(),
            'unit_price' => 0,
            'currency' => 'EUR',
            'service_type' => 'RORO_EXPORT',
            'transport_mode' => 'RORO',
        ]);

        CarrierSurchargeRule::create([
            'carrier_id' => $carrier->id,
            'port_id' => $pod->id,
            'vehicle_category' => 'car',
            'event_code' => 'TEST_SUR',
            'name' => 'Test surcharge',
            'calc_mode' => 'FLAT',
            'params' => ['amount' => 100],
            'is_active' => true,
            'article_id' => $surchargeArticle->id,
        ]);

        $service = app(CarrierRuleIntegrationService::class);
        $service->processCommodityItem($item->fresh(['quotationRequest.selectedSchedule.podPort']));

        $this->assertDatabaseHas('quotation_request_articles', [
            'quotation_request_id' => $quotation->id,
            'article_cache_id' => $surchargeArticle->id,
            'carrier_rule_applied' => true,
            'carrier_rule_event_code' => 'TEST_SUR',
            'carrier_rule_commodity_item_id' => $item->id,
        ]);
    }
}

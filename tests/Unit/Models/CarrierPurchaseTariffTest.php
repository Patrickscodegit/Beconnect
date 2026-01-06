<?php

namespace Tests\Unit\Models;

use App\Models\CarrierArticleMapping;
use App\Models\CarrierPurchaseTariff;
use App\Models\RobawsArticleCache;
use App\Models\ShippingCarrier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarrierPurchaseTariffTest extends TestCase
{
    use RefreshDatabase;

    private ShippingCarrier $carrier;
    private CarrierArticleMapping $mapping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->carrier = ShippingCarrier::create([
            'name' => 'Test Carrier',
            'code' => 'TEST',
            'is_active' => true,
        ]);

        $article = RobawsArticleCache::create([
            'robaws_article_id' => 1,
            'article_code' => 'TEST001',
            'article_name' => 'Test Article',
            'category' => 'general',
            'is_parent_article' => true,
            'is_active' => true,
            'last_synced_at' => now(),
        ]);

        $this->mapping = CarrierArticleMapping::create([
            'carrier_id' => $this->carrier->id,
            'article_id' => $article->id,
            'name' => 'Test Mapping',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_filters_active_tariffs_by_is_active()
    {
        CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'is_active' => true,
        ]);

        CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 2000.00,
            'is_active' => false,
        ]);

        $activeTariffs = CarrierPurchaseTariff::active()->get();

        $this->assertCount(1, $activeTariffs);
        $this->assertEquals(1000.00, $activeTariffs->first()->base_freight_amount);
    }

    /** @test */
    public function it_filters_active_tariffs_by_effective_dates()
    {
        // Active tariff (within date range)
        CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'is_active' => true,
            'effective_from' => Carbon::now()->subDays(10),
            'effective_to' => Carbon::now()->addDays(10),
        ]);

        // Inactive tariff (expired)
        CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 2000.00,
            'is_active' => true,
            'effective_from' => Carbon::now()->subDays(20),
            'effective_to' => Carbon::now()->subDays(5),
        ]);

        // Inactive tariff (not yet effective)
        CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 3000.00,
            'is_active' => true,
            'effective_from' => Carbon::now()->addDays(5),
            'effective_to' => Carbon::now()->addDays(20),
        ]);

        $activeTariffs = CarrierPurchaseTariff::active()->get();

        $this->assertCount(1, $activeTariffs);
        $this->assertEquals(1000.00, $activeTariffs->first()->base_freight_amount);
    }

    /** @test */
    public function it_allows_null_dates_for_always_active_tariffs()
    {
        CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'is_active' => true,
            'effective_from' => null,
            'effective_to' => null,
        ]);

        $activeTariffs = CarrierPurchaseTariff::active()->get();

        $this->assertCount(1, $activeTariffs);
    }

    /** @test */
    public function carrier_article_mapping_can_retrieve_active_purchase_tariff()
    {
        // Create inactive tariff (should not be returned)
        CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 500.00,
            'is_active' => false,
            'sort_order' => 0,
        ]);

        // Create active tariff (should be returned)
        CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $activeTariff = $this->mapping->activePurchaseTariff();

        $this->assertNotNull($activeTariff);
        $this->assertEquals(1000.00, $activeTariff->base_freight_amount);
    }

    /** @test */
    public function carrier_article_mapping_returns_null_when_no_active_tariff()
    {
        CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'is_active' => false,
        ]);

        $activeTariff = $this->mapping->activePurchaseTariff();

        $this->assertNull($activeTariff);
    }

    /** @test */
    public function purchase_tariffs_are_ordered_by_sort_order_then_effective_from()
    {
        CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'sort_order' => 2,
            'effective_from' => Carbon::now()->subDays(5),
        ]);

        CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 2000.00,
            'sort_order' => 1,
            'effective_from' => Carbon::now()->subDays(10),
        ]);

        CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 3000.00,
            'sort_order' => 1,
            'effective_from' => Carbon::now()->subDays(3),
        ]);

        $tariffs = $this->mapping->purchaseTariffs()->get();

        $this->assertEquals(2000.00, $tariffs->get(0)->base_freight_amount); // sort_order 1, effective_from -10 days
        $this->assertEquals(3000.00, $tariffs->get(1)->base_freight_amount); // sort_order 1, effective_from -3 days (more recent)
        $this->assertEquals(1000.00, $tariffs->get(2)->base_freight_amount); // sort_order 2
    }

    /** @test */
    public function it_casts_surcharge_amounts_correctly()
    {
        $tariff = CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'baf_amount' => 75.50,
            'ets_amount' => 29.75,
            'port_additional_amount' => 12.25,
            'admin_fxe_amount' => 26.00,
            'thc_amount' => 10.50,
            'measurement_costs_amount' => 2.33,
            'congestion_surcharge_amount' => 150.99,
            'iccm_amount' => 67.00,
        ]);

        $tariff->refresh();

        $this->assertIsFloat($tariff->baf_amount);
        $this->assertEquals(75.50, $tariff->baf_amount);
        $this->assertIsFloat($tariff->ets_amount);
        $this->assertEquals(29.75, $tariff->ets_amount);
        $this->assertIsFloat($tariff->port_additional_amount);
        $this->assertEquals(12.25, $tariff->port_additional_amount);
        $this->assertIsFloat($tariff->admin_fxe_amount);
        $this->assertEquals(26.00, $tariff->admin_fxe_amount);
        $this->assertIsFloat($tariff->thc_amount);
        $this->assertEquals(10.50, $tariff->thc_amount);
        $this->assertIsFloat($tariff->measurement_costs_amount);
        $this->assertEquals(2.33, $tariff->measurement_costs_amount);
        $this->assertIsFloat($tariff->congestion_surcharge_amount);
        $this->assertEquals(150.99, $tariff->congestion_surcharge_amount);
        $this->assertIsFloat($tariff->iccm_amount);
        $this->assertEquals(67.00, $tariff->iccm_amount);
    }

    /** @test */
    public function surcharges_total_sums_all_surcharges_correctly()
    {
        $tariff = CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'baf_amount' => 75.00,
            'ets_amount' => 29.00,
            'port_additional_amount' => 12.00,
            'admin_fxe_amount' => 26.00,
            'thc_amount' => 10.00,
            'measurement_costs_amount' => 2.00,
            'congestion_surcharge_amount' => 150.00,
            'iccm_amount' => 67.00,
        ]);

        $expectedTotal = 75.00 + 29.00 + 12.00 + 26.00 + 10.00 + 2.00 + 150.00 + 67.00;
        $this->assertEquals($expectedTotal, $tariff->surcharges_total);
    }

    /** @test */
    public function surcharges_total_treats_null_as_zero()
    {
        $tariff = CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'baf_amount' => 75.00,
            'ets_amount' => null,
            'port_additional_amount' => 12.00,
            'admin_fxe_amount' => null,
            'thc_amount' => 10.00,
            'measurement_costs_amount' => null,
            'congestion_surcharge_amount' => null,
            'iccm_amount' => null,
        ]);

        $expectedTotal = 75.00 + 12.00 + 10.00; // Only non-null values
        $this->assertEquals($expectedTotal, $tariff->surcharges_total);
    }

    /** @test */
    public function surcharges_total_returns_zero_when_all_null()
    {
        $tariff = CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'baf_amount' => null,
            'ets_amount' => null,
            'port_additional_amount' => null,
            'admin_fxe_amount' => null,
            'thc_amount' => null,
            'measurement_costs_amount' => null,
            'congestion_surcharge_amount' => null,
            'iccm_amount' => null,
        ]);

        $this->assertEquals(0.0, $tariff->surcharges_total);
    }

    /** @test */
    public function surcharges_total_rounds_to_two_decimals()
    {
        $tariff = CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'baf_amount' => 75.333,
            'ets_amount' => 29.666,
            'port_additional_amount' => 12.111,
        ]);

        // Should round to 2 decimals: 75.33 + 29.67 + 12.11 = 117.11
        $this->assertEquals(117.11, $tariff->surcharges_total);
    }

    /** @test */
    public function total_purchase_cost_includes_base_freight_and_surcharges()
    {
        $tariff = CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'baf_amount' => 75.00,
            'ets_amount' => 29.00,
            'port_additional_amount' => 12.00,
            'admin_fxe_amount' => 26.00,
        ]);

        $expectedTotal = 1000.00 + 75.00 + 29.00 + 12.00 + 26.00;
        $this->assertEquals($expectedTotal, $tariff->total_purchase_cost);
    }

    /** @test */
    public function total_purchase_cost_equals_base_freight_when_no_surcharges()
    {
        $tariff = CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'baf_amount' => null,
            'ets_amount' => null,
        ]);

        $this->assertEquals(1000.00, $tariff->total_purchase_cost);
    }

    /** @test */
    public function has_surcharges_returns_true_when_any_surcharge_is_set()
    {
        $tariff = CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'baf_amount' => 75.00,
            'ets_amount' => null,
        ]);

        $this->assertTrue($tariff->hasSurcharges());
    }

    /** @test */
    public function has_surcharges_returns_false_when_all_surcharges_are_null()
    {
        $tariff = CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'baf_amount' => null,
            'ets_amount' => null,
            'port_additional_amount' => null,
            'admin_fxe_amount' => null,
            'thc_amount' => null,
            'measurement_costs_amount' => null,
            'congestion_surcharge_amount' => null,
            'iccm_amount' => null,
        ]);

        $this->assertFalse($tariff->hasSurcharges());
    }

    /** @test */
    public function has_surcharges_returns_false_when_all_surcharges_are_zero()
    {
        $tariff = CarrierPurchaseTariff::create([
            'carrier_article_mapping_id' => $this->mapping->id,
            'base_freight_amount' => 1000.00,
            'baf_amount' => 0,
            'ets_amount' => 0,
            'port_additional_amount' => 0,
        ]);

        $this->assertFalse($tariff->hasSurcharges());
    }
}

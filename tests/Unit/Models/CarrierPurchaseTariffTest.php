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
            'is_parent_article' => true,
            'is_active' => true,
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
}

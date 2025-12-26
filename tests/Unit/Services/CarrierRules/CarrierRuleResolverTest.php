<?php

namespace Tests\Unit\Services\CarrierRules;

use App\Models\CarrierAcceptanceRule;
use App\Models\CarrierCategoryGroup;
use App\Models\CarrierCategoryGroupMember;
use App\Models\CarrierClassificationBand;
use App\Models\CarrierSurchargeArticleMap;
use App\Models\CarrierSurchargeRule;
use App\Models\CarrierTransformRule;
use App\Models\Port;
use App\Models\RobawsArticleCache;
use App\Models\ShippingCarrier;
use App\Services\CarrierRules\CarrierRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarrierRuleResolverTest extends TestCase
{
    use RefreshDatabase;

    private CarrierRuleResolver $resolver;
    private ShippingCarrier $carrier;
    private Port $port;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(CarrierRuleResolver::class);

        $this->carrier = ShippingCarrier::create([
            'name' => 'Test Carrier',
            'code' => 'TEST',
            'is_active' => true,
        ]);

        $this->port = Port::create([
            'code' => 'ABJ',
            'name' => 'Abidjan',
            'country' => 'Côte d\'Ivoire',
            'type' => 'pod',
        ]);
    }

    /** @test */
    public function it_resolves_classification_band_by_cbm_and_height()
    {
        // Create classification band: car if CBM < 15
        CarrierClassificationBand::create([
            'carrier_id' => $this->carrier->id,
            'outcome_vehicle_category' => 'car',
            'max_cbm' => 15,
            'max_height_cm' => 200,
            'rule_logic' => 'AND',
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        // Cargo: 12 CBM, 180cm height
        $band = $this->resolver->resolveClassificationBand(
            $this->carrier->id,
            null,
            12,
            180
        );

        $this->assertNotNull($band);
        $this->assertEquals('car', $band->outcome_vehicle_category);
    }

    /** @test */
    public function it_prefers_vessel_specific_acceptance_rule()
    {
        // Global rule
        $globalRule = CarrierAcceptanceRule::create([
            'carrier_id' => $this->carrier->id,
            'port_id' => null,
            'vehicle_category' => 'car',
            'max_length_cm' => 600,
            'priority' => 5,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        // Vessel-specific rule (higher specificity)
        $vesselRule = CarrierAcceptanceRule::create([
            'carrier_id' => $this->carrier->id,
            'port_id' => null,
            'vehicle_category' => 'car',
            'vessel_name' => 'Vessel A',
            'max_length_cm' => 550,
            'priority' => 5,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        $result = $this->resolver->resolveAcceptanceRule(
            $this->carrier->id,
            null,
            'car',
            null,
            'Vessel A',
            null
        );

        $this->assertNotNull($result);
        $this->assertEquals($vesselRule->id, $result->id);
        $this->assertEquals(550, $result->max_length_cm);
    }

    /** @test */
    public function it_prefers_port_specific_rule_over_global()
    {
        // Global rule
        CarrierAcceptanceRule::create([
            'carrier_id' => $this->carrier->id,
            'port_id' => null,
            'vehicle_category' => 'car',
            'max_weight_kg' => 3500,
            'priority' => 5,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        // Port-specific rule
        $portRule = CarrierAcceptanceRule::create([
            'carrier_id' => $this->carrier->id,
            'port_id' => $this->port->id,
            'vehicle_category' => 'car',
            'max_weight_kg' => 3000,
            'priority' => 5,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        $result = $this->resolver->resolveAcceptanceRule(
            $this->carrier->id,
            $this->port->id,
            'car',
            null,
            null,
            null
        );

        $this->assertNotNull($result);
        $this->assertEquals($portRule->id, $result->id);
        $this->assertEquals(3000, $result->max_weight_kg);
    }

    /** @test */
    public function it_resolves_category_group_based_acceptance_rule()
    {
        // Create category group
        $group = CarrierCategoryGroup::create([
            'carrier_id' => $this->carrier->id,
            'code' => 'LM_CARGO',
            'display_name' => 'LM Cargo',
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        CarrierCategoryGroupMember::create([
            'carrier_category_group_id' => $group->id,
            'vehicle_category' => 'truck',
            'is_active' => true,
        ]);

        // Create acceptance rule for group
        $groupRule = CarrierAcceptanceRule::create([
            'carrier_id' => $this->carrier->id,
            'category_group_id' => $group->id,
            'max_length_cm' => 1600,
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        $result = $this->resolver->resolveAcceptanceRule(
            $this->carrier->id,
            null,
            'truck',
            $group->id,
            null,
            null
        );

        $this->assertNotNull($result);
        $this->assertEquals($groupRule->id, $result->id);
    }

    /** @test */
    public function it_resolves_multiple_surcharge_rules()
    {
        // Create multiple surcharge rules
        CarrierSurchargeRule::create([
            'carrier_id' => $this->carrier->id,
            'event_code' => 'TRACKING_PERCENT',
            'name' => 'Tracking Surcharge',
            'calc_mode' => 'PERCENT_OF_BASIC_FREIGHT',
            'params' => ['percentage' => 10],
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        CarrierSurchargeRule::create([
            'carrier_id' => $this->carrier->id,
            'event_code' => 'TOWING',
            'name' => 'Towing Surcharge',
            'calc_mode' => 'PER_UNIT',
            'params' => ['amount' => 150],
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        $rules = $this->resolver->resolveSurchargeRules(
            $this->carrier->id,
            null,
            null,
            null,
            null,
            null
        );

        $this->assertCount(2, $rules);
        $this->assertTrue($rules->contains('event_code', 'TRACKING_PERCENT'));
        $this->assertTrue($rules->contains('event_code', 'TOWING'));
    }

    /** @test */
    public function it_resolves_article_map_for_event_code()
    {
        // Create article
        $article = RobawsArticleCache::create([
            'article_code' => 'TEST001',
            'article_name' => 'Test Surcharge',
            'unit_price' => 100,
            'currency' => 'EUR',
        ]);

        // Create article map
        $map = CarrierSurchargeArticleMap::create([
            'carrier_id' => $this->carrier->id,
            'event_code' => 'TRACKING_PERCENT',
            'article_id' => $article->id,
            'qty_mode' => 'PERCENT_OF_BASIC_FREIGHT',
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        $result = $this->resolver->resolveArticleMap(
            $this->carrier->id,
            null,
            null,
            null,
            'TRACKING_PERCENT',
            null,
            null
        );

        $this->assertNotNull($result);
        $this->assertEquals($map->id, $result->id);
        $this->assertEquals($article->id, $result->article_id);
    }
}


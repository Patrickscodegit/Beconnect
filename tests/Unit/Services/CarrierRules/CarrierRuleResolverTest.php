<?php

namespace Tests\Unit\Services\CarrierRules;

use App\Models\CarrierAcceptanceRule;
use App\Models\CarrierArticleMapping;
use App\Models\CarrierCategoryGroup;
use App\Models\CarrierCategoryGroupMember;
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

        // Test legacy category_group_id (backward compatibility)
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

        // Test new category_group_ids array
        $groupRule2 = CarrierAcceptanceRule::create([
            'carrier_id' => $this->carrier->id,
            'category_group_ids' => [$group->id],
            'max_length_cm' => 1700,
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        $result2 = $this->resolver->resolveAcceptanceRule(
            $this->carrier->id,
            null,
            'truck',
            $group->id,
            null,
            null
        );

        $this->assertNotNull($result2);
        // Should match either rule (both match), but selectMostSpecific will pick one
        $this->assertContains($result2->id, [$groupRule->id, $groupRule2->id]);
    }

    /** @test */
    public function it_resolves_multiple_category_groups_acceptance_rule()
    {
        // Create two category groups
        $group1 = CarrierCategoryGroup::create([
            'carrier_id' => $this->carrier->id,
            'code' => 'CARS',
            'display_name' => 'Cars',
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        $group2 = CarrierCategoryGroup::create([
            'carrier_id' => $this->carrier->id,
            'code' => 'VANS',
            'display_name' => 'Vans',
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        CarrierCategoryGroupMember::create([
            'carrier_category_group_id' => $group1->id,
            'vehicle_category' => 'car',
            'is_active' => true,
        ]);

        CarrierCategoryGroupMember::create([
            'carrier_category_group_id' => $group2->id,
            'vehicle_category' => 'small_van',
            'is_active' => true,
        ]);

        // Create acceptance rule with multiple category groups
        $groupRule = CarrierAcceptanceRule::create([
            'carrier_id' => $this->carrier->id,
            'category_group_ids' => [$group1->id, $group2->id],
            'max_length_cm' => 500,
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        // Test with cargo in group1
        $result1 = $this->resolver->resolveAcceptanceRule(
            $this->carrier->id,
            null,
            'car',
            $group1->id,
            null,
            null
        );

        $this->assertNotNull($result1);
        $this->assertEquals($groupRule->id, $result1->id);

        // Test with cargo in group2
        $result2 = $this->resolver->resolveAcceptanceRule(
            $this->carrier->id,
            null,
            'small_van',
            $group2->id,
            null,
            null
        );

        $this->assertNotNull($result2);
        $this->assertEquals($groupRule->id, $result2->id);
    }

    /** @test */
    public function it_resolves_article_mappings_with_numeric_category_group_ids()
    {
        $group = CarrierCategoryGroup::create([
            'carrier_id' => $this->carrier->id,
            'code' => 'CARS',
            'display_name' => 'Cars',
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        CarrierCategoryGroupMember::create([
            'carrier_category_group_id' => $group->id,
            'vehicle_category' => 'car',
            'is_active' => true,
        ]);

        $article = RobawsArticleCache::create([
            'robaws_article_id' => 1001,
            'article_name' => 'Test Car Article',
            'category' => 'car',
            'commodity_type' => 'Car',
            'is_parent_item' => true,
            'is_active' => true,
            'shipping_carrier_id' => $this->carrier->id,
            'last_synced_at' => now(),
            'last_modified_at' => now(),
        ]);

        CarrierArticleMapping::create([
            'carrier_id' => $this->carrier->id,
            'article_id' => $article->id,
            'category_group_ids' => [$group->id],
            'port_ids' => [$this->port->id],
            'is_active' => true,
        ]);

        $mappings = $this->resolver->resolveArticleMappings(
            $this->carrier->id,
            $this->port->id,
            null,
            $group->id
        );

        $this->assertTrue($mappings->pluck('article_id')->contains($article->id));
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

}


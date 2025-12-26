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
use App\Services\CarrierRules\CarrierRuleEngine;
use App\Services\CarrierRules\DTOs\CargoInputDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarrierRuleEngineTest extends TestCase
{
    use RefreshDatabase;

    private CarrierRuleEngine $engine;
    private ShippingCarrier $carrier;
    private Port $port;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(CarrierRuleEngine::class);

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
    public function it_classifies_cargo_via_classification_bands()
    {
        // Create classification band
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

        $input = new CargoInputDTO(
            carrierId: $this->carrier->id,
            podPortId: null,
            lengthCm: 450,
            widthCm: 180,
            heightCm: 150,
            cbm: 12.15,
            weightKg: 1200,
            unitCount: 1
        );

        $result = $this->engine->processCargo($input);

        $this->assertEquals('car', $result->classifiedVehicleCategory);
    }

    /** @test */
    public function it_validates_acceptance_and_reports_violations()
    {
        // Create acceptance rule
        CarrierAcceptanceRule::create([
            'carrier_id' => $this->carrier->id,
            'vehicle_category' => 'car',
            'max_length_cm' => 500,
            'max_width_cm' => 250,
            'max_height_cm' => 200,
            'max_weight_kg' => 3500,
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        // Cargo that exceeds limits
        $input = new CargoInputDTO(
            carrierId: $this->carrier->id,
            podPortId: null,
            lengthCm: 600, // Exceeds 500cm
            widthCm: 260,  // Exceeds 250cm
            heightCm: 220, // Exceeds 200cm
            cbm: 34.32,
            weightKg: 4000, // Exceeds 3500kg
            unitCount: 1,
            category: 'car'
        );

        $result = $this->engine->processCargo($input);

        $this->assertEquals('NOT_ALLOWED', $result->acceptanceStatus);
        $this->assertContains('max_length_exceeded', $result->violations);
        $this->assertContains('max_width_exceeded', $result->violations);
        $this->assertContains('max_height_exceeded', $result->violations);
        $this->assertContains('max_weight_exceeded', $result->violations);
    }

    /** @test */
    public function it_applies_overwidth_transform()
    {
        // Create transform rule
        CarrierTransformRule::create([
            'carrier_id' => $this->carrier->id,
            'transform_code' => 'OVERWIDTH_LM_RECALC',
            'params' => [
                'trigger_width_gt_cm' => 260,
                'divisor_cm' => 250,
            ],
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        $input = new CargoInputDTO(
            carrierId: $this->carrier->id,
            podPortId: null,
            lengthCm: 600,
            widthCm: 280, // > 260cm trigger
            heightCm: 250,
            cbm: 42,
            weightKg: 2000,
            unitCount: 1,
            category: 'truck'
        );

        $result = $this->engine->processCargo($input);

        // Should have applied transform
        $this->assertNotNull($result->chargeableMeasure->appliedTransformRuleId);
        $this->assertNotEquals(
            $result->chargeableMeasure->baseLm,
            $result->chargeableMeasure->chargeableLm
        );
    }

    /** @test */
    public function it_calculates_surcharge_events()
    {
        // Create surcharge rule
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

        $input = new CargoInputDTO(
            carrierId: $this->carrier->id,
            podPortId: null,
            lengthCm: 500,
            widthCm: 200,
            heightCm: 180,
            cbm: 18,
            weightKg: 1500,
            unitCount: 1,
            category: 'car'
        );

        $result = $this->engine->processCargo($input);

        $this->assertCount(1, $result->surchargeEvents);
        $this->assertEquals('TOWING', $result->surchargeEvents[0]['event_code']);
        $this->assertEquals(1, $result->surchargeEvents[0]['qty']);
    }

    /** @test */
    public function it_applies_exclusive_group_logic()
    {
        // Create two overwidth rules in same exclusive group
        CarrierSurchargeRule::create([
            'carrier_id' => $this->carrier->id,
            'event_code' => 'OVERWIDTH_LM_BASIS',
            'name' => 'Overwidth LM Basis',
            'calc_mode' => 'WIDTH_LM_BASIS',
            'params' => [
                'trigger_width_gt_cm' => 260,
                'amount_per_lm' => 25,
                'exclusive_group' => 'OVERWIDTH',
            ],
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        CarrierSurchargeRule::create([
            'carrier_id' => $this->carrier->id,
            'event_code' => 'OVERWIDTH_STEP_BLOCKS',
            'name' => 'Overwidth Step Blocks',
            'calc_mode' => 'WIDTH_STEP_BLOCKS',
            'params' => [
                'trigger_width_gt_cm' => 260,
                'threshold_cm' => 250,
                'block_cm' => 25,
                'rounding' => 'CEIL',
                'qty_basis' => 'LM',
                'amount_per_block' => 50,
                'exclusive_group' => 'OVERWIDTH',
            ],
            'priority' => 5, // Lower priority
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        $input = new CargoInputDTO(
            carrierId: $this->carrier->id,
            podPortId: null,
            lengthCm: 600,
            widthCm: 280, // > 260cm
            heightCm: 250,
            cbm: 42,
            weightKg: 2000,
            unitCount: 1,
            category: 'truck'
        );

        $result = $this->engine->processCargo($input);

        // Should only apply one rule from exclusive group (higher priority)
        $overwidthEvents = array_filter(
            $result->surchargeEvents,
            fn($e) => str_contains($e['event_code'], 'OVERWIDTH')
        );
        $this->assertCount(1, $overwidthEvents);
        $this->assertEquals('OVERWIDTH_LM_BASIS', $overwidthEvents[0]['event_code']);
    }

    /** @test */
    public function it_maps_surcharge_events_to_articles()
    {
        // Create article
        $article = RobawsArticleCache::create([
            'article_code' => 'SURCH001',
            'article_name' => 'Towing Surcharge',
            'unit_price' => 150,
            'currency' => 'EUR',
        ]);

        // Create surcharge rule
        CarrierSurchargeRule::create([
            'carrier_id' => $this->carrier->id,
            'event_code' => 'TOWING',
            'name' => 'Towing',
            'calc_mode' => 'PER_UNIT',
            'params' => ['amount' => 150],
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        // Create article map
        CarrierSurchargeArticleMap::create([
            'carrier_id' => $this->carrier->id,
            'event_code' => 'TOWING',
            'article_id' => $article->id,
            'qty_mode' => 'PER_UNIT',
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        $input = new CargoInputDTO(
            carrierId: $this->carrier->id,
            podPortId: null,
            lengthCm: 500,
            widthCm: 200,
            heightCm: 180,
            cbm: 18,
            weightKg: 1500,
            unitCount: 1,
            category: 'car'
        );

        $result = $this->engine->processCargo($input);

        $this->assertCount(1, $result->quoteLineDrafts);
        $this->assertEquals($article->id, $result->quoteLineDrafts[0]['article_id']);
        $this->assertEquals(1, $result->quoteLineDrafts[0]['qty']);
    }

    /** @test */
    public function it_handles_soft_limits_with_approval_required()
    {
        CarrierAcceptanceRule::create([
            'carrier_id' => $this->carrier->id,
            'vehicle_category' => 'truck',
            'max_height_cm' => 400,
            'soft_max_height_cm' => 450,
            'soft_height_requires_approval' => true,
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        $input = new CargoInputDTO(
            carrierId: $this->carrier->id,
            podPortId: null,
            lengthCm: 600,
            widthCm: 250,
            heightCm: 430, // Between max (400) and soft max (450)
            cbm: 64.5,
            weightKg: 5000,
            unitCount: 1,
            category: 'truck'
        );

        $result = $this->engine->processCargo($input);

        $this->assertEquals('ALLOWED_UPON_REQUEST', $result->acceptanceStatus);
        $this->assertContains('soft_height_approval', $result->approvalsRequired);
    }
}


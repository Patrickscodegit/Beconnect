<?php

namespace Tests\Unit\Services\CarrierRules;

use App\Models\CarrierSurchargeRule;
use App\Models\ShippingCarrier;
use App\Services\CarrierRules\CarrierSurchargeCalculator;
use App\Services\CarrierRules\DTOs\CargoInputDTO;
use App\Services\CarrierRules\ChargeableMeasureDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarrierSurchargeCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private CarrierSurchargeCalculator $calculator;
    private ShippingCarrier $carrier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = app(CarrierSurchargeCalculator::class);

        $this->carrier = ShippingCarrier::create([
            'name' => 'Test Carrier',
            'code' => 'TEST',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_calculates_flat_amount()
    {
        $rule = CarrierSurchargeRule::create([
            'carrier_id' => $this->carrier->id,
            'event_code' => 'FLAT_FEE',
            'name' => 'Flat Fee',
            'calc_mode' => 'FLAT',
            'params' => ['amount' => 50],
            'is_active' => true,
        ]);

        $input = new CargoInputDTO(
            carrierId: $this->carrier->id,
            podPortId: null,
            lengthCm: 500,
            widthCm: 200,
            heightCm: 180,
            cbm: 18,
            weightKg: 1500,
            unitCount: 1
        );

        $chargeableMeasure = new ChargeableMeasureDTO(
            baseLm: 5.0,
            chargeableLm: 5.0,
            appliedTransformRuleId: null,
            meta: []
        );

        $result = $this->calculator->calculate($rule, $input, $chargeableMeasure);

        $this->assertEquals(1, $result['qty']);
        $this->assertEquals(50, $result['amount']);
        $this->assertEquals('FLAT', $result['amount_basis']);
    }

    /** @test */
    public function it_calculates_per_unit()
    {
        $rule = CarrierSurchargeRule::create([
            'carrier_id' => $this->carrier->id,
            'event_code' => 'TOWING',
            'name' => 'Towing',
            'calc_mode' => 'PER_UNIT',
            'params' => ['amount' => 150],
            'is_active' => true,
        ]);

        $input = new CargoInputDTO(
            carrierId: $this->carrier->id,
            podPortId: null,
            lengthCm: 500,
            widthCm: 200,
            heightCm: 180,
            cbm: 18,
            weightKg: 1500,
            unitCount: 3 // 3 units
        );

        $chargeableMeasure = new ChargeableMeasureDTO(
            baseLm: 5.0,
            chargeableLm: 5.0,
            appliedTransformRuleId: null,
            meta: []
        );

        $result = $this->calculator->calculate($rule, $input, $chargeableMeasure);

        $this->assertEquals(3, $result['qty']);
        $this->assertEquals(150, $result['amount']);
        $this->assertEquals('PER_UNIT', $result['amount_basis']);
    }

    /** @test */
    public function it_calculates_percent_of_basic_freight()
    {
        $rule = CarrierSurchargeRule::create([
            'carrier_id' => $this->carrier->id,
            'event_code' => 'TRACKING_PERCENT',
            'name' => 'Tracking',
            'calc_mode' => 'PERCENT_OF_BASIC_FREIGHT',
            'params' => ['percentage' => 10],
            'is_active' => true,
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
            basicFreightAmount: 1000
        );

        $chargeableMeasure = new ChargeableMeasureDTO(
            baseLm: 5.0,
            chargeableLm: 5.0,
            appliedTransformRuleId: null,
            meta: []
        );

        $result = $this->calculator->calculate($rule, $input, $chargeableMeasure, 1000);

        $this->assertEquals(1, $result['qty']);
        $this->assertEquals(100, $result['amount']); // 10% of 1000
        $this->assertEquals('PERCENT_OF_BASIC_FREIGHT', $result['amount_basis']);
    }

    /** @test */
    public function it_calculates_width_step_blocks()
    {
        $rule = CarrierSurchargeRule::create([
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
            ],
            'is_active' => true,
        ]);

        $input = new CargoInputDTO(
            carrierId: $this->carrier->id,
            podPortId: null,
            lengthCm: 600,
            widthCm: 288, // 38cm over 250cm = 2 blocks (ceil(38/25) = 2)
            heightCm: 250,
            cbm: 43.2,
            weightKg: 2000,
            unitCount: 1
        );

        $chargeableMeasure = new ChargeableMeasureDTO(
            baseLm: 6.912, // (600/100 × 288/100) / 2.5
            chargeableLm: 6.912,
            appliedTransformRuleId: null,
            meta: []
        );

        $result = $this->calculator->calculate($rule, $input, $chargeableMeasure);

        // Blocks: ceil((288 - 250) / 25) = ceil(38/25) = ceil(1.52) = 2
        // Qty: 2 blocks × 6.912 LM = 13.824
        $this->assertEquals(13.824, round($result['qty'], 3));
        $this->assertEquals(50, $result['amount']); // Amount per block
        $this->assertEquals('WIDTH_STEP_BLOCKS', $result['amount_basis']);
    }

    /** @test */
    public function it_calculates_width_lm_basis()
    {
        $rule = CarrierSurchargeRule::create([
            'carrier_id' => $this->carrier->id,
            'event_code' => 'OVERWIDTH_LM_BASIS',
            'name' => 'Overwidth LM Basis',
            'calc_mode' => 'WIDTH_LM_BASIS',
            'params' => [
                'trigger_width_gt_cm' => 260,
                'amount_per_lm' => 25,
            ],
            'is_active' => true,
        ]);

        $input = new CargoInputDTO(
            carrierId: $this->carrier->id,
            podPortId: null,
            lengthCm: 600,
            widthCm: 280, // > 260cm trigger
            heightCm: 250,
            cbm: 42,
            weightKg: 2000,
            unitCount: 1
        );

        $chargeableMeasure = new ChargeableMeasureDTO(
            baseLm: 6.72, // (600/100 × 280/100) / 2.5
            chargeableLm: 6.72,
            appliedTransformRuleId: null,
            meta: []
        );

        $result = $this->calculator->calculate($rule, $input, $chargeableMeasure);

        // Qty: 6.72 LM
        // Amount: 25 per LM
        $this->assertEquals(6.72, round($result['qty'], 2));
        $this->assertEquals(25, $result['amount']);
        $this->assertEquals('WIDTH_LM_BASIS', $result['amount_basis']);
    }

    /** @test */
    public function it_does_not_apply_width_step_blocks_when_below_trigger()
    {
        $rule = CarrierSurchargeRule::create([
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
            ],
            'is_active' => true,
        ]);

        $input = new CargoInputDTO(
            carrierId: $this->carrier->id,
            podPortId: null,
            lengthCm: 600,
            widthCm: 250, // Not > 260cm trigger
            heightCm: 250,
            cbm: 37.5,
            weightKg: 2000,
            unitCount: 1
        );

        $chargeableMeasure = new ChargeableMeasureDTO(
            baseLm: 6.0,
            chargeableLm: 6.0,
            appliedTransformRuleId: null,
            meta: []
        );

        $result = $this->calculator->calculate($rule, $input, $chargeableMeasure);

        // Should return 0 qty when below trigger
        $this->assertEquals(0, $result['qty']);
    }

    /** @test */
    public function it_calculates_weight_tier()
    {
        $rule = CarrierSurchargeRule::create([
            'carrier_id' => $this->carrier->id,
            'event_code' => 'WEIGHT_TIER',
            'name' => 'Weight Tier',
            'calc_mode' => 'WEIGHT_TIER',
            'params' => [
                'tiers' => [
                    ['max_kg' => 10000, 'amount' => 120],
                    ['max_kg' => 15000, 'amount' => 180],
                    ['max_kg' => 20000, 'amount' => 250],
                    ['max_kg' => null, 'amount' => 500], // Above 20t
                ],
            ],
            'is_active' => true,
        ]);

        // Test tier 1: 8000kg
        $input1 = new CargoInputDTO(
            carrierId: $this->carrier->id,
            podPortId: null,
            lengthCm: 600,
            widthCm: 250,
            heightCm: 250,
            cbm: 37.5,
            weightKg: 8000,
            unitCount: 1
        );

        $chargeableMeasure = new ChargeableMeasureDTO(
            baseLm: 6.0,
            chargeableLm: 6.0,
            appliedTransformRuleId: null,
            meta: []
        );

        $result1 = $this->calculator->calculate($rule, $input1, $chargeableMeasure);
        $this->assertEquals(1, $result1['qty']);
        $this->assertEquals(120, $result1['amount']); // Tier 1

        // Test tier 4: 25000kg (above 20t)
        $input2 = new CargoInputDTO(
            carrierId: $this->carrier->id,
            podPortId: null,
            lengthCm: 600,
            widthCm: 250,
            heightCm: 250,
            cbm: 37.5,
            weightKg: 25000,
            unitCount: 1
        );

        $result2 = $this->calculator->calculate($rule, $input2, $chargeableMeasure);
        $this->assertEquals(1, $result2['qty']);
        $this->assertEquals(500, $result2['amount']); // Tier 4 (above 20t)
    }
}


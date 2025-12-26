<?php

namespace Tests\Unit\Services\CarrierRules;

use App\Models\CarrierTransformRule;
use App\Models\Port;
use App\Models\ShippingCarrier;
use App\Services\CarrierRules\CarrierRuleResolver;
use App\Services\CarrierRules\ChargeableMeasureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChargeableMeasureServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChargeableMeasureService $service;
    private ShippingCarrier $carrier;
    private Port $port;

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = app(CarrierRuleResolver::class);
        $this->service = new ChargeableMeasureService($resolver);

        // Create test carrier
        $this->carrier = ShippingCarrier::create([
            'name' => 'Test Carrier',
            'code' => 'TEST',
            'is_active' => true,
        ]);

        // Create test port
        $this->port = Port::create([
            'code' => 'ABJ',
            'name' => 'Abidjan',
            'country' => 'Côte d\'Ivoire',
            'type' => 'pod',
        ]);
    }

    /** @test */
    public function it_calculates_base_iso_lm_with_minimum_width()
    {
        // Standard car: 450cm × 180cm (width < 250cm, so uses 250cm minimum)
        $result = $this->service->calculateBaseLm(450, 180);
        
        // Expected: (450/100 × 250/100) / 2.5 = (4.5 × 2.5) / 2.5 = 4.5 LM
        $this->assertEquals(4.5, $result, 'Base LM should be 4.5 for 450cm × 180cm');
    }

    /** @test */
    public function it_calculates_base_iso_lm_with_actual_width_when_over_250cm()
    {
        // Van: 600cm × 260cm (width > 250cm, so uses actual width)
        $result = $this->service->calculateBaseLm(600, 260);
        
        // Expected: (600/100 × 260/100) / 2.5 = (6 × 2.6) / 2.5 = 6.24 LM
        $this->assertEquals(6.24, $result, 'Base LM should be 6.24 for 600cm × 260cm');
    }

    /** @test */
    public function it_returns_zero_for_zero_dimensions()
    {
        $result = $this->service->calculateBaseLm(0, 100);
        $this->assertEquals(0, $result);

        $result = $this->service->calculateBaseLm(100, 0);
        $this->assertEquals(0, $result);
    }

    /** @test */
    public function it_returns_base_lm_when_no_carrier_context()
    {
        // No carrier context, should return base ISO LM
        $result = $this->service->computeChargeableLm(500, 200, null);

        $this->assertEquals(5.0, $result->baseLm); // (500/100 × 250/100) / 2.5 = 5.0
        $this->assertEquals(5.0, $result->chargeableLm); // Same as base when no transforms
        $this->assertNull($result->appliedTransformRuleId);
    }

    /** @test */
    public function it_applies_overwidth_transform_when_trigger_exceeded()
    {
        // Create transform rule: trigger at 260cm, divisor 250cm
        $transformRule = CarrierTransformRule::create([
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

        // Cargo: 600cm × 280cm (width > 260cm trigger)
        $result = $this->service->computeChargeableLm(
            600,
            280,
            $this->carrier->id,
            null,
            null,
            null,
            null
        );

        // Base LM: (600/100 × max(280/100, 2.5)) / 2.5 = (6 × 2.8) / 2.5 = 6.72
        $this->assertEquals(6.72, round($result->baseLm, 2));

        // Chargeable LM: (600 × 280) / (250 × 100) = 168000 / 25000 = 6.72
        // Actually, let me recalculate: (L_cm × W_cm) / (divisor_cm × 100)
        // = (600 × 280) / (250 × 100) = 168000 / 25000 = 6.72
        $this->assertEquals(6.72, round($result->chargeableLm, 2));
        $this->assertEquals($transformRule->id, $result->appliedTransformRuleId);
    }

    /** @test */
    public function it_does_not_apply_transform_when_width_below_trigger()
    {
        // Create transform rule: trigger at 260cm
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

        // Cargo: 600cm × 250cm (width = 250cm, not > 260cm)
        $result = $this->service->computeChargeableLm(
            600,
            250,
            $this->carrier->id
        );

        // Should use base LM, not transform
        $this->assertEquals(6.0, round($result->baseLm, 2)); // (6 × 2.5) / 2.5 = 6.0
        $this->assertEquals(6.0, round($result->chargeableLm, 2));
        $this->assertNull($result->appliedTransformRuleId);
    }

    /** @test */
    public function it_uses_port_specific_transform_when_available()
    {
        // Global rule: trigger 260cm
        CarrierTransformRule::create([
            'carrier_id' => $this->carrier->id,
            'port_id' => null,
            'transform_code' => 'OVERWIDTH_LM_RECALC',
            'params' => [
                'trigger_width_gt_cm' => 260,
                'divisor_cm' => 250,
            ],
            'priority' => 5,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        // Port-specific rule: trigger 255cm (higher priority)
        $portRule = CarrierTransformRule::create([
            'carrier_id' => $this->carrier->id,
            'port_id' => $this->port->id,
            'transform_code' => 'OVERWIDTH_LM_RECALC',
            'params' => [
                'trigger_width_gt_cm' => 255,
                'divisor_cm' => 250,
            ],
            'priority' => 10,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        // Cargo: 600cm × 258cm (between 255 and 260)
        $result = $this->service->computeChargeableLm(
            600,
            258,
            $this->carrier->id,
            $this->port->id
        );

        // Should use port-specific rule (258 > 255)
        $this->assertEquals($portRule->id, $result->appliedTransformRuleId);
    }
}


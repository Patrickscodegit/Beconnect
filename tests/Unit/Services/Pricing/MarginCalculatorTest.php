<?php

namespace Tests\Unit\Services\Pricing;

use App\Models\PricingProfile;
use App\Models\PricingRule;
use App\Services\Pricing\MarginCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarginCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private MarginCalculator $calculator;
    private PricingProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new MarginCalculator();

        $this->profile = PricingProfile::create([
            'name' => 'Test Profile',
            'currency' => 'EUR',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_calculates_fixed_margin()
    {
        PricingRule::create([
            'pricing_profile_id' => $this->profile->id,
            'vehicle_category' => 'CAR',
            'unit_basis' => 'UNIT',
            'margin_type' => 'FIXED',
            'margin_value' => 50.00,
            'is_active' => true,
        ]);

        $margin = $this->calculator->calculateMargin('CAR', 'UNIT', 1000.00, $this->profile);

        $this->assertEquals(50.00, $margin);
    }

    /** @test */
    public function it_calculates_percent_margin()
    {
        PricingRule::create([
            'pricing_profile_id' => $this->profile->id,
            'vehicle_category' => 'CAR',
            'unit_basis' => 'UNIT',
            'margin_type' => 'PERCENT',
            'margin_value' => 15.00, // 15%
            'is_active' => true,
        ]);

        $margin = $this->calculator->calculateMargin('CAR', 'UNIT', 1000.00, $this->profile);

        $this->assertEquals(150.00, $margin); // 15% of 1000
    }

    /** @test */
    public function it_matches_exact_rule_first()
    {
        // Global rule (should not be used)
        PricingRule::create([
            'pricing_profile_id' => $this->profile->id,
            'vehicle_category' => null,
            'unit_basis' => null,
            'margin_type' => 'FIXED',
            'margin_value' => 10.00,
            'is_active' => true,
        ]);

        // Exact match rule (should be used)
        PricingRule::create([
            'pricing_profile_id' => $this->profile->id,
            'vehicle_category' => 'CAR',
            'unit_basis' => 'UNIT',
            'margin_type' => 'FIXED',
            'margin_value' => 50.00,
            'is_active' => true,
        ]);

        $margin = $this->calculator->calculateMargin('CAR', 'UNIT', 1000.00, $this->profile);

        $this->assertEquals(50.00, $margin);
    }

    /** @test */
    public function it_falls_back_to_category_only_rule()
    {
        // Category-only rule (should be used)
        PricingRule::create([
            'pricing_profile_id' => $this->profile->id,
            'vehicle_category' => 'CAR',
            'unit_basis' => null,
            'margin_type' => 'FIXED',
            'margin_value' => 40.00,
            'is_active' => true,
        ]);

        // Global rule (should not be used)
        PricingRule::create([
            'pricing_profile_id' => $this->profile->id,
            'vehicle_category' => null,
            'unit_basis' => null,
            'margin_type' => 'FIXED',
            'margin_value' => 10.00,
            'is_active' => true,
        ]);

        $margin = $this->calculator->calculateMargin('CAR', 'UNIT', 1000.00, $this->profile);

        $this->assertEquals(40.00, $margin);
    }

    /** @test */
    public function it_falls_back_to_basis_only_rule()
    {
        // Basis-only rule (should be used)
        PricingRule::create([
            'pricing_profile_id' => $this->profile->id,
            'vehicle_category' => null,
            'unit_basis' => 'UNIT',
            'margin_type' => 'FIXED',
            'margin_value' => 30.00,
            'is_active' => true,
        ]);

        // Global rule (should not be used)
        PricingRule::create([
            'pricing_profile_id' => $this->profile->id,
            'vehicle_category' => null,
            'unit_basis' => null,
            'margin_type' => 'FIXED',
            'margin_value' => 10.00,
            'is_active' => true,
        ]);

        $margin = $this->calculator->calculateMargin('CAR', 'UNIT', 1000.00, $this->profile);

        $this->assertEquals(30.00, $margin);
    }

    /** @test */
    public function it_falls_back_to_global_rule()
    {
        // Global rule (should be used)
        PricingRule::create([
            'pricing_profile_id' => $this->profile->id,
            'vehicle_category' => null,
            'unit_basis' => null,
            'margin_type' => 'FIXED',
            'margin_value' => 20.00,
            'is_active' => true,
        ]);

        $margin = $this->calculator->calculateMargin('CAR', 'UNIT', 1000.00, $this->profile);

        $this->assertEquals(20.00, $margin);
    }

    /** @test */
    public function it_returns_zero_when_no_rule_matches()
    {
        // Inactive rule (should not be used)
        PricingRule::create([
            'pricing_profile_id' => $this->profile->id,
            'vehicle_category' => 'CAR',
            'unit_basis' => 'UNIT',
            'margin_type' => 'FIXED',
            'margin_value' => 50.00,
            'is_active' => false,
        ]);

        $margin = $this->calculator->calculateMargin('CAR', 'UNIT', 1000.00, $this->profile);

        $this->assertEquals(0.0, $margin);
    }

    /** @test */
    public function it_only_uses_active_rules()
    {
        // Inactive rule (should not be used)
        PricingRule::create([
            'pricing_profile_id' => $this->profile->id,
            'vehicle_category' => 'CAR',
            'unit_basis' => 'UNIT',
            'margin_type' => 'FIXED',
            'margin_value' => 50.00,
            'is_active' => false,
        ]);

        // Active global rule (should be used)
        PricingRule::create([
            'pricing_profile_id' => $this->profile->id,
            'vehicle_category' => null,
            'unit_basis' => null,
            'margin_type' => 'FIXED',
            'margin_value' => 20.00,
            'is_active' => true,
        ]);

        $margin = $this->calculator->calculateMargin('CAR', 'UNIT', 1000.00, $this->profile);

        $this->assertEquals(20.00, $margin);
    }

    /** @test */
    public function it_handles_percent_margin_calculation_correctly()
    {
        PricingRule::create([
            'pricing_profile_id' => $this->profile->id,
            'vehicle_category' => 'CAR',
            'unit_basis' => 'UNIT',
            'margin_type' => 'PERCENT',
            'margin_value' => 12.5, // 12.5%
            'is_active' => true,
        ]);

        $margin = $this->calculator->calculateMargin('CAR', 'UNIT', 2000.00, $this->profile);

        $this->assertEquals(250.00, $margin); // 12.5% of 2000
    }
}

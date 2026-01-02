<?php

namespace Tests\Unit\Services\Pricing;

use App\Models\PricingProfile;
use App\Models\RobawsCustomerCache;
use App\Models\ShippingCarrier;
use App\Services\Pricing\PricingProfileResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingProfileResolverTest extends TestCase
{
    use RefreshDatabase;

    private PricingProfileResolver $resolver;
    private ShippingCarrier $carrier;
    private RobawsCustomerCache $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new PricingProfileResolver();

        $this->carrier = ShippingCarrier::create([
            'name' => 'Test Carrier',
            'code' => 'TEST',
            'is_active' => true,
        ]);

        $this->customer = RobawsCustomerCache::create([
            'robaws_client_id' => 'CLIENT123',
            'name' => 'Test Customer',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_resolves_client_specific_profile_with_highest_priority()
    {
        // Global profile
        PricingProfile::create([
            'name' => 'Global Profile',
            'is_active' => true,
            'carrier_id' => null,
            'robaws_client_id' => null,
        ]);

        // Carrier default profile
        PricingProfile::create([
            'name' => 'Carrier Profile',
            'is_active' => true,
            'carrier_id' => $this->carrier->id,
            'robaws_client_id' => null,
        ]);

        // Client-specific profile (should be returned)
        $clientProfile = PricingProfile::create([
            'name' => 'Client Profile',
            'is_active' => true,
            'carrier_id' => null,
            'robaws_client_id' => $this->customer->robaws_client_id,
        ]);

        $resolved = $this->resolver->resolve(
            $this->carrier->id,
            $this->customer->robaws_client_id
        );

        $this->assertNotNull($resolved);
        $this->assertEquals($clientProfile->id, $resolved->id);
        $this->assertEquals('Client Profile', $resolved->name);
    }

    /** @test */
    public function it_resolves_carrier_default_profile_when_no_client_specific()
    {
        // Global profile
        PricingProfile::create([
            'name' => 'Global Profile',
            'is_active' => true,
            'carrier_id' => null,
            'robaws_client_id' => null,
        ]);

        // Carrier default profile (should be returned)
        $carrierProfile = PricingProfile::create([
            'name' => 'Carrier Profile',
            'is_active' => true,
            'carrier_id' => $this->carrier->id,
            'robaws_client_id' => null,
        ]);

        $resolved = $this->resolver->resolve(
            $this->carrier->id,
            null
        );

        $this->assertNotNull($resolved);
        $this->assertEquals($carrierProfile->id, $resolved->id);
        $this->assertEquals('Carrier Profile', $resolved->name);
    }

    /** @test */
    public function it_resolves_global_profile_when_no_carrier_or_client_specific()
    {
        // Global profile (should be returned)
        $globalProfile = PricingProfile::create([
            'name' => 'Global Profile',
            'is_active' => true,
            'carrier_id' => null,
            'robaws_client_id' => null,
        ]);

        $resolved = $this->resolver->resolve(null, null);

        $this->assertNotNull($resolved);
        $this->assertEquals($globalProfile->id, $resolved->id);
        $this->assertEquals('Global Profile', $resolved->name);
    }

    /** @test */
    public function it_filters_by_date_validity()
    {
        // Expired profile
        PricingProfile::create([
            'name' => 'Expired Profile',
            'is_active' => true,
            'effective_from' => Carbon::now()->subDays(20),
            'effective_to' => Carbon::now()->subDays(5),
            'carrier_id' => null,
            'robaws_client_id' => null,
        ]);

        // Active profile (should be returned)
        $activeProfile = PricingProfile::create([
            'name' => 'Active Profile',
            'is_active' => true,
            'effective_from' => Carbon::now()->subDays(10),
            'effective_to' => Carbon::now()->addDays(10),
            'carrier_id' => null,
            'robaws_client_id' => null,
        ]);

        $resolved = $this->resolver->resolve(null, null);

        $this->assertNotNull($resolved);
        $this->assertEquals($activeProfile->id, $resolved->id);
    }

    /** @test */
    public function it_allows_null_dates_for_always_active_profiles()
    {
        $profile = PricingProfile::create([
            'name' => 'Always Active Profile',
            'is_active' => true,
            'effective_from' => null,
            'effective_to' => null,
            'carrier_id' => null,
            'robaws_client_id' => null,
        ]);

        $resolved = $this->resolver->resolve(null, null);

        $this->assertNotNull($resolved);
        $this->assertEquals($profile->id, $resolved->id);
    }

    /** @test */
    public function it_returns_null_when_no_matching_profile()
    {
        // Inactive profile
        PricingProfile::create([
            'name' => 'Inactive Profile',
            'is_active' => false,
            'carrier_id' => null,
            'robaws_client_id' => null,
        ]);

        $resolved = $this->resolver->resolve(null, null);

        $this->assertNull($resolved);
    }

    /** @test */
    public function it_respects_custom_date_parameter()
    {
        $date = Carbon::now()->addDays(30);

        // Profile active in future (should be returned with future date)
        $futureProfile = PricingProfile::create([
            'name' => 'Future Profile',
            'is_active' => true,
            'effective_from' => Carbon::now()->addDays(20),
            'effective_to' => Carbon::now()->addDays(40),
            'carrier_id' => null,
            'robaws_client_id' => null,
        ]);

        $resolved = $this->resolver->resolve(null, null, $date);

        $this->assertNotNull($resolved);
        $this->assertEquals($futureProfile->id, $resolved->id);
    }
}

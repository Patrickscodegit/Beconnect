<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\PricingTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourcePricingTierTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function test_admin_can_set_pricing_tier_for_customer_user(): void
    {
        $tierA = PricingTier::where('code', 'A')->firstOrFail();
        $tierB = PricingTier::where('code', 'B')->firstOrFail();

        $customer = User::factory()->create([
            'role' => 'customer',
            'status' => 'active',
            'pricing_tier_id' => $tierA->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditUser::class, ['record' => $customer->getRouteKey()])
            ->fillForm([
                'pricing_tier_id' => $tierB->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($tierB->id, $customer->fresh()->pricing_tier_id);
    }

    public function test_admin_can_set_pricing_tier_for_admin_user(): void
    {
        $tierA = PricingTier::where('code', 'A')->firstOrFail();
        $tierB = PricingTier::where('code', 'B')->firstOrFail();

        $adminTarget = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'pricing_tier_id' => $tierA->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditUser::class, ['record' => $adminTarget->getRouteKey()])
            ->fillForm([
                'pricing_tier_id' => $tierB->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($tierB->id, $adminTarget->fresh()->pricing_tier_id);
    }

    public function test_pricing_tier_options_include_current_inactive_tier(): void
    {
        $tierA = PricingTier::where('code', 'A')->firstOrFail();
        $tierC = PricingTier::where('code', 'C')->firstOrFail();
        $tierC->update(['is_active' => false]);

        $customer = User::factory()->create([
            'role' => 'customer',
            'status' => 'active',
            'pricing_tier_id' => $tierC->id,
        ]);

        $method = new \ReflectionMethod(UserResource::class, 'getPricingTierOptions');
        $method->setAccessible(true);
        $options = $method->invoke(null, $customer->fresh(), null);

        $this->assertArrayHasKey($tierA->id, $options);
        $this->assertArrayHasKey($tierC->id, $options);
    }
}

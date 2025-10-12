<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Livewire\Livewire;
use App\Filament\Resources\QuotationRequestResource\Pages\CreateQuotationRequest;

class FilamentQuotationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
    }

    public function test_can_access_quotation_create_page()
    {
        $this->actingAs($this->user)
            ->get('/admin/quotation-requests/create')
            ->assertStatus(200);
    }

    public function test_can_access_quotation_list_page()
    {
        $this->actingAs($this->user)
            ->get('/admin/quotation-requests')
            ->assertStatus(200);
    }

    public function test_can_load_create_quotation_page()
    {
        $this->actingAs($this->user);
        
        // Just test that we can load the Livewire component without errors
        $component = Livewire::test(CreateQuotationRequest::class);
        $this->assertInstanceOf(CreateQuotationRequest::class, $component->instance());
    }
}

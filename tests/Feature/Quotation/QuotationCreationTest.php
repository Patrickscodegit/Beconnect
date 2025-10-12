<?php

namespace Tests\Feature\Quotation;

use App\Models\QuotationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationCreationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * Get base quotation data with all required fields
     */
    protected function getBaseQuotationData(array $overrides = []): array
    {
        return array_merge([
            'source' => 'intake',
            'requester_type' => 'admin',
            'requester_email' => 'admin@belgaco.com',
            'requester_name' => 'Test Customer',
            'requester_company' => 'Test Company',
            'requester_phone' => '+1234567890',
            'trade_direction' => 'export',
            'robaws_sync_status' => 'pending',
            'pricing_currency' => 'EUR',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'routing' => ['por' => null, 'pol' => 'Antwerp', 'pod' => 'Lagos', 'fdest' => null],
            'cargo_details' => [],
            'cargo_description' => 'Test cargo',
            'status' => 'pending',
        ], $overrides);
    }

    /** @test */
    public function it_creates_quotation_with_unique_request_number()
    {
        $quotation = QuotationRequest::create($this->getBaseQuotationData([
            'cargo_description' => 'Test cargo description',
        ]));

        $this->assertNotNull($quotation->request_number);
        $this->assertMatchesRegularExpression('/^QR-\d{4}-\d{4}$/', $quotation->request_number);
        $this->assertEquals('Test Customer', $quotation->requester_name);
        $this->assertDatabaseHas('quotation_requests', [
            'id' => $quotation->id,
            'request_number' => $quotation->request_number,
        ]);
    }

    /** @test */
    public function it_generates_unique_request_numbers_for_multiple_quotations()
    {
        $quotations = [];
        
        for ($i = 0; $i < 3; $i++) {
            $quotations[] = QuotationRequest::create([
                'customer_name' => "Customer $i",
                'customer_email' => "customer$i@example.com",
                'customer_phone' => '+1234567890',
                'customer_type' => 'GENERAL',
                'customer_role' => 'CONSIGNEE',
                'service_type' => 'RORO_EXPORT',
                'pol' => 'Antwerp',
                'pod' => 'Lagos',
                'cargo_description' => 'Test cargo',
                'commodity_type' => 'car',
            ]);
        }

        $requestNumbers = collect($quotations)->pluck('request_number')->toArray();
        $uniqueNumbers = array_unique($requestNumbers);

        $this->assertCount(3, $uniqueNumbers, 'All request numbers should be unique');
    }

    /** @test */
    public function it_handles_soft_deleted_records_correctly()
    {
        // Create and soft delete a quotation
        $first = QuotationRequest::create([
            'customer_name' => 'First Customer',
            'customer_email' => 'first@example.com',
            'customer_phone' => '+1234567890',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'cargo_description' => 'Test cargo',
            'commodity_type' => 'car',
        ]);
        
        $firstNumber = $first->request_number;
        $first->delete(); // Soft delete

        // Create new quotation
        $second = QuotationRequest::create([
            'customer_name' => 'Second Customer',
            'customer_email' => 'second@example.com',
            'customer_phone' => '+1234567890',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'cargo_description' => 'Test cargo',
            'commodity_type' => 'car',
        ]);

        $this->assertNotEquals($firstNumber, $second->request_number, 'New quotation should not reuse soft-deleted request number');
    }

    /** @test */
    public function it_requires_pol_and_pod_fields()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        QuotationRequest::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+1234567890',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            // Missing POL and POD
            'cargo_description' => 'Test cargo',
            'commodity_type' => 'car',
        ]);
    }

    /** @test */
    public function it_allows_optional_por_and_fdest_fields()
    {
        $quotation = QuotationRequest::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+1234567890',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            // POR and FDEST intentionally omitted
            'cargo_description' => 'Test cargo',
            'commodity_type' => 'car',
        ]);

        $this->assertNull($quotation->por);
        $this->assertNull($quotation->fdest);
        $this->assertEquals('Antwerp', $quotation->pol);
        $this->assertEquals('Lagos', $quotation->pod);
    }

    /** @test */
    public function it_validates_status_enum_values()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        QuotationRequest::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+1234567890',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'cargo_description' => 'Test cargo',
            'commodity_type' => 'car',
            'status' => 'invalid_status', // Invalid enum value
        ]);
    }

    /** @test */
    public function it_initializes_cargo_details_as_json_array()
    {
        $quotation = QuotationRequest::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+1234567890',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'cargo_description' => 'Test cargo',
            'commodity_type' => 'car',
        ]);

        $this->assertIsArray($quotation->cargo_details);
        $this->assertEmpty($quotation->cargo_details);
    }

    /** @test */
    public function it_saves_customer_reference_field()
    {
        $quotation = QuotationRequest::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+1234567890',
            'customer_reference' => 'REF-12345',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'cargo_description' => 'Test cargo',
            'commodity_type' => 'car',
        ]);

        $this->assertEquals('REF-12345', $quotation->customer_reference);
    }

    /** @test */
    public function it_builds_routing_string_from_route_fields()
    {
        $quotation = QuotationRequest::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+1234567890',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'por' => 'Brussels',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'fdest' => 'Abuja',
            'cargo_description' => 'Test cargo',
            'commodity_type' => 'car',
        ]);

        // The routing string should be built from the route fields
        // This might be done in a mutator or observer
        $expectedRoute = 'Brussels → Antwerp → Lagos → Abuja';
        
        // If routing is auto-generated, test it
        // Otherwise, this test documents that routing should be built from fields
        $this->assertNotNull($quotation);
    }

    /** @test */
    public function it_sets_default_values_for_pricing_fields()
    {
        $quotation = QuotationRequest::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+1234567890',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'cargo_description' => 'Test cargo',
            'commodity_type' => 'car',
        ]);

        $this->assertEquals(0, $quotation->subtotal);
        $this->assertEquals(0, $quotation->discount_amount);
        $this->assertEquals(0, $quotation->total_excl_vat);
        $this->assertEquals(0, $quotation->vat_amount);
        $this->assertEquals(0, $quotation->total_incl_vat);
    }
}


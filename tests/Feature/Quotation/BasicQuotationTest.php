<?php

namespace Tests\Feature\Quotation;

use App\Models\QuotationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BasicQuotationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_quotation_creation_works()
    {
        $data = [
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
        ];

        $quotation = QuotationRequest::create($data);

        $this->assertNotNull($quotation->request_number);
        $this->assertMatchesRegularExpression('/^QR-\d{4}-\d{4}$/', $quotation->request_number);
        $this->assertEquals('Test Customer', $quotation->requester_name);
        $this->assertEquals('RORO_EXPORT', $quotation->service_type);
        $this->assertEquals('Antwerp', $quotation->pol);
        $this->assertEquals('Lagos', $quotation->pod);
    }

    public function test_unique_request_numbers()
    {
        // Create first quotation
        $first = QuotationRequest::create([
            'source' => 'intake',
            'requester_type' => 'admin',
            'requester_email' => 'admin@belgaco.com',
            'requester_name' => 'First Customer',
            'trade_direction' => 'export',
            'robaws_sync_status' => 'pending',
            'pricing_currency' => 'EUR',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'routing' => [],
            'cargo_details' => [],
            'cargo_description' => 'Test cargo',
            'status' => 'pending',
        ]);

        // Create second quotation
        $second = QuotationRequest::create([
            'source' => 'intake',
            'requester_type' => 'admin',
            'requester_email' => 'admin@belgaco.com',
            'requester_name' => 'Second Customer',
            'trade_direction' => 'export',
            'robaws_sync_status' => 'pending',
            'pricing_currency' => 'EUR',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'routing' => [],
            'cargo_details' => [],
            'cargo_description' => 'Test cargo',
            'status' => 'pending',
        ]);

        $this->assertNotEquals($first->request_number, $second->request_number);
        $this->assertMatchesRegularExpression('/^QR-\d{4}-\d{4}$/', $first->request_number);
        $this->assertMatchesRegularExpression('/^QR-\d{4}-\d{4}$/', $second->request_number);
    }

    public function test_soft_deleted_handling()
    {
        // Create and soft delete first quotation
        $first = QuotationRequest::create([
            'source' => 'intake',
            'requester_type' => 'admin',
            'requester_email' => 'admin@belgaco.com',
            'requester_name' => 'First Customer',
            'trade_direction' => 'export',
            'robaws_sync_status' => 'pending',
            'pricing_currency' => 'EUR',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'routing' => [],
            'cargo_details' => [],
            'cargo_description' => 'Test cargo',
            'status' => 'pending',
        ]);

        $firstNumber = $first->request_number;
        $first->delete(); // Soft delete

        // Create new quotation - should not reuse the soft-deleted number
        $second = QuotationRequest::create([
            'source' => 'intake',
            'requester_type' => 'admin',
            'requester_email' => 'admin@belgaco.com',
            'requester_name' => 'Second Customer',
            'trade_direction' => 'export',
            'robaws_sync_status' => 'pending',
            'pricing_currency' => 'EUR',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'routing' => [],
            'cargo_details' => [],
            'cargo_description' => 'Test cargo',
            'status' => 'pending',
        ]);

        $this->assertNotEquals($firstNumber, $second->request_number);
    }

    public function test_required_fields()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        // Try to create quotation without required fields
        QuotationRequest::create([
            'customer_type' => 'GENERAL',
            'service_type' => 'RORO_EXPORT',
            // Missing required fields like source, pol, pod, etc.
        ]);
    }

    public function test_optional_route_fields()
    {
        // Test with only POL and POD (port-to-port shipment)
        $quotation = QuotationRequest::create([
            'source' => 'intake',
            'requester_type' => 'admin',
            'requester_email' => 'admin@belgaco.com',
            'requester_name' => 'Test Customer',
            'trade_direction' => 'export',
            'robaws_sync_status' => 'pending',
            'pricing_currency' => 'EUR',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'routing' => [],
            'cargo_details' => [],
            'cargo_description' => 'Test cargo',
            'status' => 'pending',
            // POR and FDEST intentionally omitted
        ]);

        $this->assertNull($quotation->por);
        $this->assertNull($quotation->fdest);
        $this->assertEquals('Antwerp', $quotation->pol);
        $this->assertEquals('Lagos', $quotation->pod);
    }

    public function test_customer_reference_field()
    {
        $quotation = QuotationRequest::create([
            'source' => 'intake',
            'requester_type' => 'admin',
            'requester_email' => 'admin@belgaco.com',
            'requester_name' => 'Test Customer',
            'customer_reference' => 'REF-12345',
            'trade_direction' => 'export',
            'robaws_sync_status' => 'pending',
            'pricing_currency' => 'EUR',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'routing' => [],
            'cargo_details' => [],
            'cargo_description' => 'Test cargo',
            'status' => 'pending',
        ]);

        $this->assertEquals('REF-12345', $quotation->customer_reference);
    }
}

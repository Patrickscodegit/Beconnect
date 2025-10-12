<?php

namespace Tests\Feature\Quotation;

use App\Models\QuotationRequest;
use App\Models\RobawsArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationDuplicateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_duplicates_quotation_with_new_unique_request_number()
    {
        $original = QuotationRequest::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+1234567890',
            'customer_reference' => 'REF-ORIGINAL',
            'customer_type' => 'GENERAL',
            'customer_role' => 'CONSIGNEE',
            'service_type' => 'RORO_EXPORT',
            'pol' => 'Antwerp',
            'pod' => 'Lagos',
            'cargo_description' => 'Test cargo',
            'commodity_type' => 'car',
            'status' => 'quoted',
        ]);

        $originalNumber = $original->request_number;

        // Simulate duplication
        $duplicate = $original->replicate();
        $duplicate->status = 'pending';
        $duplicate->customer_reference = ($original->customer_reference ?? '') . ' (Copy)';
        $duplicate->request_number = null;
        $duplicate->robaws_offer_id = null;
        $duplicate->robaws_offer_number = null;
        $duplicate->robaws_sync_status = 'pending';
        $duplicate->robaws_synced_at = null;
        $duplicate->quoted_at = null;
        $duplicate->expires_at = null;
        $duplicate->subtotal = 0;
        $duplicate->discount_amount = 0;
        $duplicate->discount_percentage = 0;
        $duplicate->total_excl_vat = 0;
        $duplicate->vat_amount = 0;
        $duplicate->total_incl_vat = 0;
        $duplicate->save();

        $this->assertNotEquals($originalNumber, $duplicate->request_number);
        $this->assertEquals('REF-ORIGINAL (Copy)', $duplicate->customer_reference);
        $this->assertEquals('pending', $duplicate->status);
        $this->assertEquals(0, $duplicate->subtotal);
        $this->assertNull($duplicate->robaws_offer_id);
    }

    /** @test */
    public function it_copies_articles_from_original_quotation()
    {
        // Create original quotation
        $original = QuotationRequest::create([
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

        // Create and attach articles
        $article1 = RobawsArticle::create([
            'robaws_article_id' => 'ART-001',
            'article_name' => 'Test Article 1',
            'article_description' => 'Description 1',
            'unit_price' => 100.00,
            'unit_type' => 'per unit',
        ]);

        $article2 = RobawsArticle::create([
            'robaws_article_id' => 'ART-002',
            'article_name' => 'Test Article 2',
            'article_description' => 'Description 2',
            'unit_price' => 200.00,
            'unit_type' => 'per unit',
        ]);

        $original->articles()->attach($article1->id, [
            'quantity' => 2,
            'unit_price' => 100.00,
            'discount_percentage' => 0,
            'subtotal' => 200.00,
        ]);

        $original->articles()->attach($article2->id, [
            'quantity' => 1,
            'unit_price' => 200.00,
            'discount_percentage' => 0,
            'subtotal' => 200.00,
        ]);

        // Duplicate quotation
        $duplicate = $original->replicate();
        $duplicate->status = 'pending';
        $duplicate->request_number = null;
        $duplicate->save();

        // Copy articles
        foreach ($original->articles as $article) {
            $duplicate->articles()->attach($article->id, [
                'quantity' => $article->pivot->quantity ?? 1,
                'unit_price' => $article->pivot->unit_price ?? 0,
                'discount_percentage' => $article->pivot->discount_percentage ?? 0,
                'subtotal' => $article->pivot->subtotal ?? 0,
            ]);
        }

        $this->assertCount(2, $duplicate->articles);
        $this->assertEquals(2, $duplicate->articles->first()->pivot->quantity);
    }

    /** @test */
    public function it_resets_pricing_fields_in_duplicate()
    {
        $original = QuotationRequest::create([
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
            'subtotal' => 1000.00,
            'discount_amount' => 100.00,
            'discount_percentage' => 10,
            'total_excl_vat' => 900.00,
            'vat_amount' => 189.00,
            'total_incl_vat' => 1089.00,
        ]);

        // Duplicate and reset pricing
        $duplicate = $original->replicate();
        $duplicate->request_number = null;
        $duplicate->status = 'pending';
        $duplicate->subtotal = 0;
        $duplicate->discount_amount = 0;
        $duplicate->discount_percentage = 0;
        $duplicate->total_excl_vat = 0;
        $duplicate->vat_amount = 0;
        $duplicate->total_incl_vat = 0;
        $duplicate->save();

        $this->assertEquals(0, $duplicate->subtotal);
        $this->assertEquals(0, $duplicate->discount_amount);
        $this->assertEquals(0, $duplicate->total_excl_vat);
        $this->assertEquals(0, $duplicate->vat_amount);
        $this->assertEquals(0, $duplicate->total_incl_vat);
    }

    /** @test */
    public function it_preserves_customer_and_route_information()
    {
        $original = QuotationRequest::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+1234567890',
            'customer_company' => 'Test Company',
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

        $duplicate = $original->replicate();
        $duplicate->request_number = null;
        $duplicate->status = 'pending';
        $duplicate->save();

        $this->assertEquals('Test Customer', $duplicate->customer_name);
        $this->assertEquals('customer@example.com', $duplicate->customer_email);
        $this->assertEquals('Test Company', $duplicate->customer_company);
        $this->assertEquals('Brussels', $duplicate->por);
        $this->assertEquals('Antwerp', $duplicate->pol);
        $this->assertEquals('Lagos', $duplicate->pod);
        $this->assertEquals('Abuja', $duplicate->fdest);
    }

    /** @test */
    public function it_clears_robaws_sync_information()
    {
        $original = QuotationRequest::create([
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
            'robaws_offer_id' => '12345',
            'robaws_offer_number' => 'OFF-001',
            'robaws_sync_status' => 'synced',
            'robaws_synced_at' => now(),
        ]);

        $duplicate = $original->replicate();
        $duplicate->request_number = null;
        $duplicate->status = 'pending';
        $duplicate->robaws_offer_id = null;
        $duplicate->robaws_offer_number = null;
        $duplicate->robaws_sync_status = 'pending';
        $duplicate->robaws_synced_at = null;
        $duplicate->save();

        $this->assertNull($duplicate->robaws_offer_id);
        $this->assertNull($duplicate->robaws_offer_number);
        $this->assertEquals('pending', $duplicate->robaws_sync_status);
        $this->assertNull($duplicate->robaws_synced_at);
    }
}


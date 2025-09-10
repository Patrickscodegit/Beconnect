<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ContactPhoneUpdateTest extends TestCase
{
    public function test_contact_phone_numbers_are_created_and_updated(): void
    {
        // Mock HTTP responses for Robaws API
        Http::fake([
            '*/api/v2/clients/4248/contacts' => Http::sequence()
                ->push([]) // First call: no contacts exist
                ->push([ // After creation: contact exists
                    'id' => 123,
                    'email' => 'nancy.deckers@armosbv.be',
                    'first_name' => 'Nancy',
                    'surname' => 'Deckers',
                    'tel' => '+3234358657',
                    'gsm' => '+32476720216',
                ], 201), // POST response for creation
        ]);
        
        $apiClient = new RobawsApiClient();
        
        // Test creating a new contact with phone numbers
        $contactData = [
            'email' => 'nancy.deckers@armosbv.be',
            'first_name' => 'Nancy',
            'surname' => 'Deckers',
            'phone' => '+3234358657',
            'mobile' => '+32476720216',
        ];
        
        $result = $apiClient->createOrUpdateClientContact(4248, $contactData);
        
        $this->assertNotNull($result);
        $this->assertEquals('nancy.deckers@armosbv.be', $result['email']);
        $this->assertEquals('+3234358657', $result['tel']);
        $this->assertEquals('+32476720216', $result['gsm']);
        
        // Verify the HTTP request was made correctly
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v2/clients/4248/contacts') &&
                   $request->method() == 'POST' &&
                   $request['email'] == 'nancy.deckers@armosbv.be' &&
                   $request['tel'] == '+3234358657' &&
                   $request['gsm'] == '+32476720216';
        });
    }

    public function test_existing_contact_phone_numbers_are_updated(): void
    {
        // Mock HTTP responses for updating existing contact
        Http::fake([
            '*/api/v2/clients/4248/contacts' => Http::response([
                [
                    'id' => 123,
                    'email' => 'nancy.deckers@armosbv.be',
                    'first_name' => 'Nancy',
                    'surname' => 'Deckers',
                    'tel' => '', // Empty phone initially
                    'gsm' => '', // Empty mobile initially
                ]
            ]), // GET response: existing contact found
            '*/api/v2/clients/4248/contacts/123' => Http::response([
                'id' => 123,
                'email' => 'nancy.deckers@armosbv.be',
                'first_name' => 'Nancy',
                'surname' => 'Deckers',
                'tel' => '+3234358657', // Updated phone
                'gsm' => '+32476720216', // Updated mobile
            ]), // PATCH response for update
        ]);
        
        $apiClient = new RobawsApiClient();
        
        // Test updating an existing contact with phone numbers
        $contactData = [
            'email' => 'nancy.deckers@armosbv.be',
            'first_name' => 'Nancy',
            'surname' => 'Deckers',
            'phone' => '+3234358657',
            'mobile' => '+32476720216',
        ];
        
        $result = $apiClient->createOrUpdateClientContact(4248, $contactData);
        
        $this->assertNotNull($result);
        $this->assertEquals('nancy.deckers@armosbv.be', $result['email']);
        $this->assertEquals('+3234358657', $result['tel']);
        $this->assertEquals('+32476720216', $result['gsm']);
        
        // Verify the HTTP request was made correctly (PATCH for update)
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v2/clients/4248/contacts/123') &&
                   $request->method() == 'PATCH' &&
                   $request['email'] == 'nancy.deckers@armosbv.be' &&
                   $request['tel'] == '+3234358657' &&
                   $request['gsm'] == '+32476720216';
        });
    }
}

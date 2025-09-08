<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Intake;
use App\Services\Export\Clients\RobawsApiClient;
use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Robaws\RobawsExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RobawsEnhancedClientCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_enhanced_customer_data_extraction()
    {
        // Create an intake with comprehensive extraction data
        $intake = Intake::factory()->create([
            'customer_name' => 'Ebele Elobi Trading Company',
            'contact_email' => 'ebele@example.com',
            'contact_phone' => '+234 1234567890',
            'extraction_data' => [
                'customer_name' => 'Ebele Elobi Trading Company',
                'email' => 'ebele@example.com',
                'phone' => '+234 1234567890',
                'mobile' => '+234 9876543210',
                'vat_number' => 'NG123456789',
                'company_number' => 'RC-987654',
                'website' => 'https://ebele-trading.com',
                'contact' => [
                    'name' => 'Ebele Elobi',
                    'first_name' => 'Ebele',
                    'last_name' => 'Elobi',
                    'email' => 'ebele@example.com',
                    'phone' => '+234 1234567890',
                    'function' => 'Managing Director',
                ],
                'sender' => 'John Smith',
                'address' => [
                    'street' => 'Victoria Island',
                    'street_number' => '123',
                    'city' => 'Lagos',
                    'postal_code' => '101241',
                    'country' => 'Nigeria',
                ],
                'shipping' => [
                    'origin' => 'Antwerp',
                    'destination' => 'Lagos',
                ],
                'vehicle' => [
                    'brand' => 'MAN',
                    'model' => 'TGX',
                    'condition' => 'used',
                ],
                'raw_text' => 'Export quote request from Antwerp to Lagos...',
            ],
        ]);

        // Map the intake data
        $mapper = new RobawsMapper();
        $mappedData = $mapper->mapIntakeToRobaws($intake);

        // Assert enhanced customer data is extracted
        $this->assertArrayHasKey('customer_data', $mappedData);
        $customerData = $mappedData['customer_data'];
        
        // Basic customer info
        $this->assertEquals('Ebele Elobi Trading Company', $customerData['name']);
        $this->assertEquals('ebele@example.com', $customerData['email']);
        $this->assertEquals('+234 1234567890', $customerData['phone']);
        $this->assertEquals('+234 9876543210', $customerData['mobile']);
        
        // Company information
        $this->assertEquals('NG123456789', $customerData['vat_number']);
        $this->assertEquals('RC-987654', $customerData['company_number']);
        $this->assertEquals('https://ebele-trading.com', $customerData['website']);
        $this->assertEquals('company', $customerData['client_type']);
        
        // Address information
        $this->assertEquals('Victoria Island', $customerData['street']);
        $this->assertEquals('123', $customerData['street_number']);
        $this->assertEquals('Lagos', $customerData['city']);
        $this->assertEquals('101241', $customerData['postal_code']);
        $this->assertEquals('Nigeria', $customerData['country']);
        
        // Contact person
        $this->assertNotNull($customerData['contact_person']);
        $this->assertEquals('Ebele Elobi', $customerData['contact_person']['name']);
        $this->assertEquals('Ebele', $customerData['contact_person']['first_name']);
        $this->assertEquals('Elobi', $customerData['contact_person']['last_name']);
        $this->assertEquals('Managing Director', $customerData['contact_person']['function']);
        $this->assertTrue($customerData['contact_person']['is_primary']);
        
        // Language detection (should detect English as default)
        $this->assertEquals('en', $customerData['language']);
        $this->assertEquals('EUR', $customerData['currency']);
    }

    public function test_client_type_detection()
    {
        $mapper = new RobawsMapper();
        
        // Test company detection via VAT number
        $intake1 = Intake::factory()->create([
            'extraction_data' => [
                'customer_name' => 'Some Business',
                'vat_number' => 'BE123456789'
            ]
        ]);
        
        $mapped1 = $mapper->mapIntakeToRobaws($intake1);
        $this->assertEquals('company', $mapped1['customer_data']['client_type']);
        
        // Test company detection via company indicators in name
        $intake2 = Intake::factory()->create([
            'extraction_data' => [
                'customer_name' => 'Tech Solutions GmbH',
            ]
        ]);
        
        $mapped2 = $mapper->mapIntakeToRobaws($intake2);
        $this->assertEquals('company', $mapped2['customer_data']['client_type']);
        
        // Test individual detection
        $intake3 = Intake::factory()->create([
            'extraction_data' => [
                'customer_name' => 'John Doe',
            ]
        ]);
        
        $mapped3 = $mapper->mapIntakeToRobaws($intake3);
        $this->assertEquals('individual', $mapped3['customer_data']['client_type']);
    }

    public function test_contact_person_formatting()
    {
        $mapper = new RobawsMapper();
        
        // Test string contact person
        $intake1 = Intake::factory()->create([
            'extraction_data' => [
                'sender' => 'Jane Smith'
            ]
        ]);
        
        $mapped1 = $mapper->mapIntakeToRobaws($intake1);
        $contactPerson = $mapped1['customer_data']['contact_person'];
        
        $this->assertEquals('Jane Smith', $contactPerson['name']);
        $this->assertEquals('Jane', $contactPerson['first_name']);
        $this->assertEquals('Smith', $contactPerson['last_name']);
        $this->assertTrue($contactPerson['is_primary']);
        
        // Test array contact person
        $intake2 = Intake::factory()->create([
            'extraction_data' => [
                'contact' => [
                    'name' => 'Michael Johnson',
                    'email' => 'michael@example.com',
                    'function' => 'Sales Manager'
                ]
            ]
        ]);
        
        $mapped2 = $mapper->mapIntakeToRobaws($intake2);
        $contactPerson2 = $mapped2['customer_data']['contact_person'];
        
        $this->assertEquals('Michael Johnson', $contactPerson2['name']);
        $this->assertEquals('michael@example.com', $contactPerson2['email']);
        $this->assertEquals('Sales Manager', $contactPerson2['function']);
        $this->assertTrue($contactPerson2['is_primary']);
    }

    public function test_language_detection()
    {
        $mapper = new RobawsMapper();
        
        // Test German detection
        $intake1 = Intake::factory()->create([
            'extraction_data' => [
                'raw_text' => 'Guten Tag, wir möchten ein Angebot für den Transport von Hamburg nach Lagos.'
            ]
        ]);
        
        $mapped1 = $mapper->mapIntakeToRobaws($intake1);
        $this->assertEquals('de', $mapped1['customer_data']['language']);
        
        // Test French detection
        $intake2 = Intake::factory()->create([
            'extraction_data' => [
                'raw_text' => 'Bonjour, nous aimerions avoir un devis pour le transport de véhicules.'
            ]
        ]);
        
        $mapped2 = $mapper->mapIntakeToRobaws($intake2);
        $this->assertEquals('fr', $mapped2['customer_data']['language']);
        
        // Test Dutch detection
        $intake3 = Intake::factory()->create([
            'extraction_data' => [
                'raw_text' => 'Wij willen graag een offerte voor het transport van auto\'s naar Nigeria.'
            ]
        ]);
        
        $mapped3 = $mapper->mapIntakeToRobaws($intake3);
        $this->assertEquals('nl', $mapped3['customer_data']['language']);
        
        // Test default English
        $intake4 = Intake::factory()->create([
            'extraction_data' => [
                'raw_text' => 'We would like a quote for car shipping to Lagos.'
            ]
        ]);
        
        $mapped4 = $mapper->mapIntakeToRobaws($intake4);
        $this->assertEquals('en', $mapped4['customer_data']['language']);
    }

    public function test_country_normalization()
    {
        $mapper = new RobawsMapper();
        
        // Test German variations
        $intake1 = Intake::factory()->create([
            'extraction_data' => [
                'shipping' => ['origin' => 'Deutschland']
            ]
        ]);
        
        $mapped1 = $mapper->mapIntakeToRobaws($intake1);
        $this->assertEquals('Germany', $mapped1['customer_data']['country']);
        
        // Test Belgian variations
        $intake2 = Intake::factory()->create([
            'extraction_data' => [
                'shipping' => ['origin' => 'Belgique']
            ]
        ]);
        
        $mapped2 = $mapper->mapIntakeToRobaws($intake2);
        $this->assertEquals('Belgium', $mapped2['customer_data']['country']);
        
        // Test port to country mapping
        $intake3 = Intake::factory()->create([
            'extraction_data' => [
                'shipping' => ['origin' => 'Antwerp']
            ]
        ]);
        
        $mapped3 = $mapper->mapIntakeToRobaws($intake3);
        $this->assertEquals('Belgium', $mapped3['customer_data']['country']);
    }
}

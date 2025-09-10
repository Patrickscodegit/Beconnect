<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Export\Clients\RobawsApiClient;

class TestArmosBVExtraction extends Command
{
    protected $signature = 'test:armos-bv';
    protected $description = 'Test customer information extraction and mapping for Armos BV case';

    public function handle()
    {
        $this->info('=== TESTING ARMOS BV CUSTOMER EXTRACTION AND MAPPING ===');
        
        // Simulate email content with Armos BV information
        $emailContent = "
        From: info@armos.be
        
        Dear colleagues,
        
        We are Armos BV, a Belgian company located at:
        Kapelsesteenweg 611
        B-2180 Antwerp (Ekeren)
        Belgium
        
        Contact details:
        Mobile: +32 (0)476 72 02 16
        Tel: +32 (0)3 435 86 57
        VAT number: 0437 311 533
        Website: www.armos.be
        
        We need transport for our RoRo shipment:
        
        1 x used BMW X5
        Dimensions: L390 cm, B230 cm, H310cm
        
        Best regards,
        Armos BV Team
        ";

        $this->info('1. Testing Pattern Extraction...');
        
        // Test pattern extraction with dependency injection
        $patternExtractor = app(PatternExtractor::class);
        $extractedData = $patternExtractor->extract($emailContent);
        
        $this->info('Extracted Data:');
        $this->info(json_encode($extractedData, JSON_PRETTY_PRINT));
        
        $this->info("\n2. Testing Customer Data Extraction...");
        
        // Extract customer-specific information
        $customerData = [
            'name' => 'Armos BV',
            'email' => 'info@armos.be',
            'phone' => '+32 (0)3 435 86 57',
            'mobile' => '+32 (0)476 72 02 16',
            'vat_number' => '0437 311 533',
            'website' => 'www.armos.be',
            'street' => 'Kapelsesteenweg 611',
            'city' => 'Antwerp (Ekeren)',
            'postal_code' => 'B-2180',
            'country' => 'Belgium',
            'client_type' => 'company'
        ];
        
        $this->info('Expected Customer Data:');
        $this->info(json_encode($customerData, JSON_PRETTY_PRINT));
        
        $this->info("\n3. Testing RobawsApiClient Payload Mapping...");
        
        // Test how RobawsApiClient maps customer data
        $apiClient = app(RobawsApiClient::class);
        $reflection = new \ReflectionClass($apiClient);
        $method = $reflection->getMethod('toRobawsClientPayload');
        $method->setAccessible(true);
        
        $robawsPayload = $method->invokeArgs($apiClient, [$customerData, false]);
        
        $this->info('Robaws API Client Payload:');
        $this->info(json_encode($robawsPayload, JSON_PRETTY_PRINT));
        
        $this->info("\n4. Testing RobawsMapper Integration...");
        
        // Simulate intake data structure
        $mockIntakeData = array_merge($extractedData, [
            'customer_name' => 'Armos BV',
            'email' => 'info@armos.be',
            'phone' => '+32 (0)3 435 86 57',
            'mobile' => '+32 (0)476 72 02 16',
            'vat_number' => '0437 311 533',
            'website' => 'www.armos.be',
            'address' => [
                'street' => 'Kapelsesteenweg 611',
                'city' => 'Antwerp (Ekeren)',
                'postal_code' => 'B-2180',
                'country' => 'Belgium'
            ],
            'contact' => [
                'name' => 'Armos BV Team',
                'email' => 'info@armos.be',
                'phone' => '+32 (0)3 435 86 57',
                'mobile' => '+32 (0)476 72 02 16',
                'company' => 'Armos BV'
            ]
        ]);
        
        // Create a mock intake object
        $mockIntake = new \stdClass();
        $mockIntake->id = 999;
        $mockIntake->customer_name = 'Armos BV';
        $mockIntake->customer_email = 'info@armos.be';
        $mockIntake->contact_phone = '+32 (0)3 435 86 57';
        $mockIntake->documents = collect();
        $mockIntake->extraction_data = [];
        
        $mapper = new RobawsMapper($apiClient);
        $mappedData = $mapper->mapIntakeToRobaws($mockIntake, $mockIntakeData);
        
        $this->info('RobawsMapper Output:');
        $this->info(json_encode($mappedData, JSON_PRETTY_PRINT));
        
        $this->info("\n5. Testing Final API Payload Generation...");
        
        $finalPayload = $mapper->toRobawsApiPayload($mappedData);
        
        $this->info('Final Robaws API Payload:');
        $this->info(json_encode($finalPayload, JSON_PRETTY_PRINT));
        
        $this->info("\n=== ANALYSIS ===");
        
        // Check what's missing
        $issues = [];
        
        if (empty($mappedData['customer_data']['name']) || $mappedData['customer_data']['name'] !== 'Armos BV') {
            $issues[] = "Customer name not properly extracted/mapped";
        }
        
        if (empty($mappedData['customer_data']['street']) || !str_contains($mappedData['customer_data']['street'], 'Kapelsesteenweg')) {
            $issues[] = "Address not properly extracted/mapped";
        }
        
        if (empty($mappedData['customer_data']['mobile']) || !str_contains($mappedData['customer_data']['mobile'], '476')) {
            $issues[] = "Mobile number not properly extracted/mapped";
        }
        
        if (empty($mappedData['customer_data']['phone']) || !str_contains($mappedData['customer_data']['phone'], '435')) {
            $issues[] = "Phone number not properly extracted/mapped";
        }
        
        if (empty($mappedData['customer_data']['vat_number']) || !str_contains($mappedData['customer_data']['vat_number'], '0437')) {
            $issues[] = "VAT number not properly extracted/mapped";
        }
        
        if (empty($mappedData['customer_data']['website']) || !str_contains($mappedData['customer_data']['website'], 'armos.be')) {
            $issues[] = "Website not properly extracted/mapped";
        }
        
        if (empty($issues)) {
            $this->info('✅ All customer information fields are properly mapped!');
        } else {
            $this->error('❌ Issues found:');
            foreach ($issues as $issue) {
                $this->error("  - $issue");
            }
        }
        
        return 0;
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SimpleRobawsIntegration;
use App\Models\Document;
use App\Models\Intake;

class TestRobawsMapping extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:robaws-mapping';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the enhanced Robaws field mapping with sample data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing Enhanced Field Mapping for Robaws Integration');
        $this->info('==================================================');
        $this->newLine();

        // Sample extracted data structure (simulating what AI extraction would provide)
        $sampleExtractionData = [
            'email_metadata' => [
                'subject' => 'Vehicle Transport Quote Request - BMW 7 Series from Brussels to Jeddah',
                'from' => 'john.smith@example.com',
                'to' => 'sales@belgaco.be',
                'date' => '2024-12-23T10:30:00+01:00'
            ],
            'contact' => [
                'name' => 'John Smith',
                'phone' => '+32 472 123 456',
                'email' => 'john.smith@example.com',
                'address' => 'Avenue Louise 123, 1050 Brussels, Belgium'
            ],
            'shipment' => [
                'origin' => 'Brussels, Belgium',
                'destination' => 'Jeddah, Saudi Arabia'
            ],
            'vehicle' => [
                'brand' => 'BMW',
                'make' => 'BMW',
                'model' => '7 Series',
                'year' => '2020',
                'color' => 'Black',
                'condition' => 'Used',
                'vin' => 'WBAGW51040DX12345',
                'fuel_type' => 'Diesel',
                'engine_cc' => '3000',
                'weight_kg' => 1950,
                'dimensions' => [
                    'length_m' => 5.12,
                    'width_m' => 1.90,
                    'height_m' => 1.48
                ]
            ],
            'messages' => [
                [
                    'sender' => 'John Smith',
                    'text' => 'I need a quote for shipping my vehicle from Brussels, Belgium to Jeddah, Saudi Arabia.',
                    'timestamp' => '2024-12-23T10:30:00+01:00'
                ]
            ],
            'metadata' => [
                'confidence_score' => 0.95
            ]
        ];

        // Test the service directly without creating documents
        $robawsService = new SimpleRobawsIntegration();
        
        // Use reflection to access the private formatForRobaws method
        $reflection = new \ReflectionClass($robawsService);
        $method = $reflection->getMethod('formatForRobaws');
        $method->setAccessible(true);
        
        $formattedData = $method->invoke($robawsService, $sampleExtractionData);

        $this->info("Enhanced field mapping completed successfully!");
        $this->newLine();

        if ($formattedData) {
            $this->info('Key Robaws Fields Check:');
            $this->info('========================');
            $this->line("Customer: " . ($formattedData['customer'] ?? 'NOT SET'));
            $this->line("Customer Reference: " . ($formattedData['customer_reference'] ?? 'NOT SET'));
            $this->line("POR (Port of Receipt): " . ($formattedData['por'] ?? 'NOT SET'));
            $this->line("POL (Port of Loading): " . ($formattedData['pol'] ?? 'NOT SET'));
            $this->line("POD (Port of Discharge): " . ($formattedData['pod'] ?? 'NOT SET'));
            $this->line("Cargo: " . ($formattedData['cargo'] ?? 'NOT SET'));
            $this->line("Dimensions: " . ($formattedData['dim_bef_delivery'] ?? 'NOT SET'));
            $this->line("Volume: " . ($formattedData['volume_m3'] ?? 'NOT SET') . " Cbm");
            $this->line("Vehicle Brand: " . ($formattedData['vehicle_brand'] ?? 'NOT SET'));
            $this->line("Vehicle Model: " . ($formattedData['vehicle_model'] ?? 'NOT SET'));
            $this->line("Vehicle Year: " . ($formattedData['vehicle_year'] ?? 'NOT SET'));
            $this->line("Contact: " . ($formattedData['contact'] ?? 'NOT SET'));
            $this->line("Email: " . ($formattedData['client_email'] ?? 'NOT SET'));
            $this->line("Internal Remarks: " . (isset($formattedData['internal_remarks']) ? substr($formattedData['internal_remarks'], 0, 100) . '...' : 'NOT SET'));
            
            $this->newLine();
            $this->info('Full Formatted Data:');
            $this->info('===================');
            $this->line(json_encode($formattedData, JSON_PRETTY_PRINT));
        } else {
            $this->error('No formatted Robaws data generated');
        }

        $this->newLine();
        $this->info('Test completed successfully!');
    }
}

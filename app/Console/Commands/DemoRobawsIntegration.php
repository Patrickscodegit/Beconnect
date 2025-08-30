<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\SimpleRobawsIntegration;
use Illuminate\Console\Command;

class DemoRobawsIntegration extends Command
{
    protected $signature = 'robaws:demo';
    protected $description = 'Demonstrate Robaws integration with sample freight forwarding data';

    public function handle()
    {
        $this->info('🚢 Demonstrating Robaws Integration with Sample Data...');
        $this->newLine();

        // Sample extracted freight forwarding data
        $sampleExtractedData = [
            'shipment_type' => 'FCL',
            'origin_port' => 'USNYC',
            'destination_port' => 'DEHAM',
            'cargo_description' => 'General Merchandise - Electronics',
            'container_type' => '20GP',
            'quantity' => 2,
            'weight' => 18500,
            'volume' => 28.5,
            'incoterms' => 'CIF',
            'payment_terms' => 'Net 30',
            'departure_date' => '2025-09-15',
            'arrival_date' => '2025-09-22',
            'consignee' => [
                'name' => 'European Electronics Import B.V.',
                'address' => 'Havenstraat 123, 2000 Hamburg, Germany',
                'contact' => '+49 40 123456789'
            ],
            'special_requirements' => 'Temperature controlled, fragile goods',
            'reference_number' => 'REF-2025-001234',
            'confidence_score' => 0.92
        ];

        $this->info('📋 Sample Extracted Data:');
        $this->line(json_encode($sampleExtractedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();

        // Initialize the service
        $robawsIntegration = app(SimpleRobawsIntegration::class);

        // Create a test document for demonstration
        $testDocument = new Document([
            'id' => 999,
            'filename' => 'demo_freight_invoice.pdf',
            'file_size' => 245000,
            'mime_type' => 'application/pdf',
            'extraction_status' => 'completed',
            'extraction_data' => $sampleExtractedData,
        ]);

        // Test the formatting
        $this->info('🔄 Formatting data for Robaws...');
        
        // Manually format the data to show what would happen
        $robawsData = [
            'freight_type' => $sampleExtractedData['shipment_type'],
            'origin_port' => $sampleExtractedData['origin_port'],
            'destination_port' => $sampleExtractedData['destination_port'],
            'cargo_description' => $sampleExtractedData['cargo_description'],
            'container_type' => $sampleExtractedData['container_type'],
            'container_quantity' => $sampleExtractedData['quantity'],
            'weight_kg' => $sampleExtractedData['weight'],
            'volume_m3' => $sampleExtractedData['volume'],
            'incoterms' => $sampleExtractedData['incoterms'],
            'payment_terms' => $sampleExtractedData['payment_terms'],
            'departure_date' => $sampleExtractedData['departure_date'],
            'arrival_date' => $sampleExtractedData['arrival_date'],
            'client_name' => $sampleExtractedData['consignee']['name'],
            'client_address' => $sampleExtractedData['consignee']['address'],
            'client_contact' => $sampleExtractedData['consignee']['contact'],
            'special_requirements' => $sampleExtractedData['special_requirements'],
            'reference_number' => $sampleExtractedData['reference_number'],
            'original_extraction' => $sampleExtractedData,
            'extraction_confidence' => $sampleExtractedData['confidence_score'],
            'formatted_at' => now()->toISOString(),
            'source' => 'bconnect_ai_extraction'
        ];

        $this->info('✅ Data formatted for Robaws!');
        $this->newLine();

        $this->info('📤 Robaws-Compatible JSON:');
        $this->line(json_encode($robawsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();

        // Show export format
        $exportData = [
            'bconnect_document' => [
                'id' => 999,
                'filename' => 'demo_freight_invoice.pdf',
                'uploaded_at' => now()->toISOString(),
                'processed_at' => now()->toISOString(),
            ],
            'robaws_quotation_data' => $robawsData
        ];

        $this->info('📦 Export Format for Manual Robaws Import:');
        $this->line(json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();

        // Show integration summary format
        $this->info('📊 Integration Summary Example:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Documents', '5'],
                ['Ready for Sync', '3'],
                ['Already Synced', '1'],
                ['Pending Formatting', '1'],
                ['Latest Ready', now()->format('Y-m-d H:i:s')],
            ]
        );

        $this->newLine();
        $this->info('🎯 Integration Workflow:');
        $this->line('1. ✅ Document uploaded and processed');
        $this->line('2. ✅ AI extraction completed with high confidence');
        $this->line('3. ✅ Data automatically formatted for Robaws');
        $this->line('4. ⏳ Ready for manual sync to Robaws quotation system');
        $this->line('5. ⏳ Mark as synced when quotation created in Robaws');

        $this->newLine();
        $this->info('💡 Next Steps for Real Integration:');
        $this->line('   1. Upload a freight document through the web interface');
        $this->line('   2. Wait for AI extraction to complete');
        $this->line('   3. Use: php artisan robaws:test-simple');
        $this->line('   4. Copy the JSON output to create quotations in Robaws manually');
        $this->line('   5. Mark documents as synced when done');

        $this->newLine();
        $this->info('🏁 Demo completed! The integration is ready to use.');

        return Command::SUCCESS;
    }
}

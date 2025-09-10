<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Intake;
use App\Services\Export\RobawsExportService;

class TestArmosExport extends Command
{
    protected $signature = 'test:armos-export {intake_id}';
    protected $description = 'Test Armos BV export with enhanced client update';

    public function handle()
    {
        $intakeId = $this->argument('intake_id');
        
        $this->info("Testing Armos BV Export for Intake {$intakeId}");
        $this->info("=" . str_repeat("=", 50));
        
        $intake = Intake::find($intakeId);
        if (!$intake) {
            $this->error("Intake {$intakeId} not found!");
            return 1;
        }
        
        $this->info("Intake Customer: " . $intake->customer_name);
        $this->info("Intake Email: " . $intake->contact_email);
        
        // Test client resolution first
        $apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
        $originalClientId = $apiClient->findClientId($intake->customer_name, $intake->contact_email);
        $this->info("Existing Client ID: " . ($originalClientId ?? 'null'));
        
        // Test manual client update
        if ($originalClientId) {
            $this->info("\n1. Testing manual client update...");
            
            $updateData = [
                'phone' => '+32034358657',
                'mobile' => '+32 (0)476 72 02 16', 
                'vat_number' => 'BE0437311533',
                'website' => 'www.armos.be',
                'street' => 'Kapelsesteenweg 611',
                'city' => 'Antwerp (Ekeren)',
                'postal_code' => 'B-2180',
                'country' => 'Belgium'
            ];
            
            $this->info("Update data: " . json_encode($updateData));
            
            try {
                $result = $apiClient->updateClient($originalClientId, $updateData);
                $this->info("Update result: " . json_encode($result));
            } catch (\Exception $e) {
                $this->error("Update failed: " . $e->getMessage());
                $this->error("Stack trace: " . $e->getTraceAsString());
            }
        }
        
        // Test the mapper with API client
        $mapper = new \App\Services\Export\Mappers\RobawsMapper($apiClient);
        
        $this->info("\n2. Testing mapper with API client...");
        
        try {
            $mapped = $mapper->mapIntakeToRobaws($intake);
            $this->info("Quotation Customer: " . ($mapped['quotation_info']['customer'] ?? 'N/A'));
            $this->info("Quotation Email: " . ($mapped['quotation_info']['contact_email'] ?? 'N/A'));
            
            $this->info("\n3. Testing API payload generation...");
            $payload = $mapper->toRobawsApiPayload($mapped);
            
            $this->info("Client ID in payload: " . ($payload['clientId'] ?? 'N/A'));
            
            // Show some client fields
            $this->info("\n4. Client extraFields:");
            foreach (['CLIENT', 'CLIENT_VAT', 'CLIENT_WEBSITE', 'CLIENT_ADDRESS'] as $field) {
                if (isset($payload['extraFields'][$field])) {
                    $value = $payload['extraFields'][$field]['stringValue'] ?? 'null';
                    $this->info("   {$field}: {$value}");
                }
            }
            
        } catch (\Exception $e) {
            $this->error("Error during mapper test: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            return 1;
        }
        
        return 0;
    }
}

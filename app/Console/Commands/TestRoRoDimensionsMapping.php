<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\VehicleDatabase\VehicleDatabaseService;
use App\Services\Export\Mappers\RobawsMapper;
use App\Models\Intake;

class TestRoRoDimensionsMapping extends Command
{
    protected $signature = 'test:roro-mapping';
    protected $description = 'Test complete RO-RO dimensions mapping to DIM_BEF_DELIVERY';

    public function handle()
    {
        $this->info('=== Testing Complete RO-RO Dimensions Mapping ===');
        $this->newLine();

        // Test content from the RO-RO email
        $testContent = 'Goede middag

Kunnen jullie me aanbieden voor Ro-Ro transport van een heftruck van Antwerpen naar MOMBASA

Details heftruck:

Jungheftruck TFG435s
L390 cm
B230 cm
H310cm
3500KG

Hoor graag

Mvgr
Nancy';

        try {
            $this->info('1. Pattern Extraction:');
            $vehicleDb = app(VehicleDatabaseService::class);
            $patternExtractor = new PatternExtractor($vehicleDb);
            $extractedData = $patternExtractor->extract($testContent);
            
            $dims = $extractedData['vehicle']['dimensions'] ?? null;
            if ($dims) {
                $this->info('✓ Dimensions extracted:');
                $this->line('  Length: ' . $dims['length_m'] . ' m');
                $this->line('  Width: ' . $dims['width_m'] . ' m');
                $this->line('  Height: ' . $dims['height_m'] . ' m');
            }
            $this->newLine();
            
            $this->info('2. Creating Mock Intake:');
            // Create a mock intake to test the mapping
            $intake = new Intake([
                'id' => 12345,
                'client_email' => 'nancy@armos.be',
                'customer_reference' => 'RO-RO-TEST',
                'extraction_data' => $extractedData
            ]);
            
            $this->info('✓ Mock intake created');
            $this->newLine();
            
            $this->info('3. Robaws Mapping:');
            $robawsMapper = new RobawsMapper();
            $mappedData = $robawsMapper->mapIntakeToRobaws($intake, $extractedData);
            
            $this->info('Mapped data structure:');
            $this->line(json_encode($mappedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
            
                        // Test the toRobawsApiPayload conversion with debugging
            $this->info('5. Debugging toRobawsApiPayload conversion:');
            $this->line('Input cargo_details:');
            $cargoDetails = $mappedData['cargo_details'] ?? [];
            $this->line(json_encode($cargoDetails, JSON_PRETTY_PRINT));
            
            // Extract just the dimensions_text for debugging
            $dimensionsText = $cargoDetails['dimensions_text'] ?? null;
            $this->line('dimensions_text value: ' . ($dimensionsText ? '"' . $dimensionsText . '"' : 'NULL'));
            
            // Check if it's empty or has whitespace issues
            if ($dimensionsText) {
                $this->line('Length: ' . strlen($dimensionsText));
                $this->line('Trimmed: "' . trim($dimensionsText) . '"');
                $this->line('Is empty after trim: ' . (trim($dimensionsText) === '' ? 'YES' : 'NO'));
            }

            $robawsData = $robawsMapper->toRobawsApiPayload($mappedData);
            
            $this->info('6. DIM_BEF_DELIVERY Field Analysis:');
            // Check in extraFields where the field is actually located
            if (isset($robawsData['extraFields']['DIM_BEF_DELIVERY']) && !empty($robawsData['extraFields']['DIM_BEF_DELIVERY']['stringValue'])) {
                $dimValue = $robawsData['extraFields']['DIM_BEF_DELIVERY']['stringValue'];
                $this->info('✅ SUCCESS! DIM_BEF_DELIVERY populated:');
                $this->line('  Value: "' . $dimValue . '"');
            } else {
                $this->error('❌ PROBLEM: DIM_BEF_DELIVERY not populated in API payload');
                
                // Debug the dimensions_text in mapped data
                if (isset($mappedData['cargo_details']['dimensions_text'])) {
                    $this->info('✓ dimensions_text exists in mapped data:');
                    $this->line('  "' . $mappedData['cargo_details']['dimensions_text'] . '"');
                    
                    // Check if it's being processed in API payload
                    $this->line('Checking API payload conversion...');
                    $dimField = $robawsData['extraFields']['DIM_BEF_DELIVERY'] ?? null;
                    $this->line('DIM_BEF_DELIVERY in API payload: ' . json_encode($dimField, JSON_PRETTY_PRINT));
                } else {
                    $this->error('❌ dimensions_text missing from mapped data');
                }
            }
            
            $this->newLine();
            $this->info('7. Full Robaws Export Structure:');
            $this->line('DIM_BEF_DELIVERY: ' . json_encode($robawsData['extraFields']['DIM_BEF_DELIVERY'] ?? 'NOT SET', JSON_PRETTY_PRINT));
            $this->line('CARGO: ' . json_encode($robawsData['extraFields']['CARGO'] ?? 'NOT SET', JSON_PRETTY_PRINT));
            
            // Show the complete extraFields structure for verification
            $this->newLine();
            $this->info('Complete extraFields structure:');
            if (isset($robawsData['extraFields'])) {
                $this->line(json_encode($robawsData['extraFields'], JSON_PRETTY_PRINT));
            } else {
                $this->line('No extraFields found');
            }
            
        } catch (\Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
            $this->line('Trace: ' . $e->getTraceAsString());
        }

        return 0;
    }
}

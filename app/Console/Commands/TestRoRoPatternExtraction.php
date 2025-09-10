<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\VehicleDatabase\VehicleDatabaseService;

class TestRoRoPatternExtraction extends Command
{
    protected $signature = 'test:roro-patterns';
    protected $description = 'Test pattern extraction on RO-RO email content';

    public function handle()
    {
        $this->info('=== Testing RO-RO Pattern Extraction ===');
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

        $this->info('1. Test Content:');
        $this->line($testContent);
        $this->newLine();

        try {
            $vehicleDb = app(VehicleDatabaseService::class);
            $patternExtractor = new PatternExtractor($vehicleDb);
            
            $this->info('2. Pattern Extraction Results:');
            $result = $patternExtractor->extract($testContent);
            
            $this->info('Full Results:');
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
            
            $this->info('3. Dimension Analysis:');
            if (isset($result['vehicle']['dimensions'])) {
                $dims = $result['vehicle']['dimensions'];
                $this->info('✓ Vehicle dimensions found:');
                $this->line('Length: ' . ($dims['length_m'] ?? 'N/A') . ' m');
                $this->line('Width: ' . ($dims['width_m'] ?? 'N/A') . ' m');
                $this->line('Height: ' . ($dims['height_m'] ?? 'N/A') . ' m');
                $this->line('Unit source: ' . ($dims['unit_source'] ?? 'N/A'));
            } else {
                $this->error('❌ No vehicle dimensions found');
            }
            
            $this->newLine();
            $this->info('4. Vehicle Data:');
            if (isset($result['vehicle'])) {
                $vehicle = $result['vehicle'];
                $this->line('Brand: ' . ($vehicle['brand'] ?? 'Not found'));
                $this->line('Model: ' . ($vehicle['model'] ?? 'Not found'));
                $this->line('Weight: ' . ($vehicle['weight_kg'] ?? 'Not found') . ' kg');
            }
            
            $this->newLine();
            $this->info('5. Route Data:');
            if (isset($result['shipment'])) {
                $shipment = $result['shipment'];
                $this->line('Origin: ' . ($shipment['origin'] ?? 'Not found'));
                $this->line('Destination: ' . ($shipment['destination'] ?? 'Not found'));
            }
            
        } catch (\Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
            $this->line('Trace: ' . $e->getTraceAsString());
        }

        return 0;
    }
}

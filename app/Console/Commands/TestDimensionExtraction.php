<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Extraction\Strategies\PatternExtractor;
use App\Services\VehicleDatabase\VehicleDatabaseService;

class TestDimensionExtraction extends Command
{
    protected $signature = 'test:dimensions {text?}';
    protected $description = 'Test dimension extraction from text';

    public function handle()
    {
        $text = $this->argument('text') ?? 
            "Motorgrader 10,06m x 2,52m x 3,12m / 18.750 kg";
        
        $this->info("Testing dimension extraction for:");
        $this->line($text);
        $this->line(str_repeat('-', 50));
        
        // Create PatternExtractor instance
        $vehicleDb = app(VehicleDatabaseService::class);
        $patternExtractor = new PatternExtractor($vehicleDb);
        
        // Test full extraction
        $extracted = $patternExtractor->extract($text);
        
        if (isset($extracted['vehicle']) && !empty($extracted['vehicle'])) {
            $this->info("✅ Vehicle data extracted successfully:");
            $vehicle = $extracted['vehicle'];
            
            $this->table(
                ['Field', 'Value'],
                collect($vehicle)->map(function ($value, $key) {
                    if (is_array($value)) {
                        return [$key, json_encode($value)];
                    }
                    return [$key, $value];
                })->toArray()
            );
        } else {
            $this->error("❌ No vehicle data found");
        }
        
        $this->line("\nTesting various dimension formats:");
        $testCases = [
            "10.06m x 2.52m x 3.12m",
            "10,06m x 2,52m x 3,12m",
            "10.06 x 2.52 x 3.12",
            "L: 10.06 W: 2.52 H: 3.12",
            "Length: 10.06m Width: 2.52m Height: 3.12m",
            "Motorgrader 10,06m x 2,52m x 3,12m / 18.750 kg",
            "Excavator (12.5m x 3.2m x 3.8m) Weight: 25000kg",
            "Bulldozer L: 8.5m W: 2.8m H: 3.1m",
        ];
        
        foreach ($testCases as $testCase) {
            $result = $patternExtractor->extract($testCase);
            $hasVehicle = !empty($result['vehicle']);
            $hasDimensions = isset($result['vehicle']['dimensions']);
            $hasWeight = isset($result['vehicle']['weight_kg']);
            
            $status = $hasVehicle ? '✅' : '❌';
            $this->line("$status $testCase");
            
            if ($hasVehicle) {
                $details = [];
                if ($hasDimensions) {
                    $dims = $result['vehicle']['dimensions'];
                    $details[] = sprintf("Dims: %.2fm x %.2fm x %.2fm", 
                        $dims['length_m'] ?? 0,
                        $dims['width_m'] ?? 0,
                        $dims['height_m'] ?? 0
                    );
                }
                if ($hasWeight) {
                    $details[] = "Weight: {$result['vehicle']['weight_kg']}kg";
                }
                if (isset($result['vehicle']['type'])) {
                    $details[] = "Type: {$result['vehicle']['type']}";
                }
                
                if (!empty($details)) {
                    $this->line("   → " . implode(', ', $details));
                }
            }
        }
        
        return Command::SUCCESS;
    }
}

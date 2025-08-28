<?php

namespace App\Console\Commands;

use App\Services\Extraction\HybridExtractionPipeline;
use App\Services\VehicleDatabase\VehicleDatabaseService;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestHybridExtraction extends Command
{
    protected $signature = 'test:hybrid-extraction {file?} {--document-id=} {--detail} {--benchmark}';
    protected $description = 'Test the hybrid extraction pipeline with database enhancement';

    public function handle(
        HybridExtractionPipeline $pipeline,
        VehicleDatabaseService $vehicleDb
    ) {
        $this->info('🚀 Testing Hybrid Extraction Pipeline with Database Enhancement');
        $this->newLine();

        // Get test content
        $content = $this->getTestContent();
        if (!$content) {
            return 1;
        }

        // Display database statistics
        $this->displayDatabaseStats($vehicleDb);

        // Run extraction
        $startTime = microtime(true);
        
        try {
            $result = $pipeline->extract($content, 'email');
            $totalTime = microtime(true) - $startTime;

            $this->displayResults($result, $totalTime);
            
            if ($this->option('benchmark')) {
                $this->runBenchmark($pipeline, $content);
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Extraction failed: ' . $e->getMessage());
            
            if ($this->option('detail')) {
                $this->error($e->getTraceAsString());
            }
            
            return 1;
        }
    }

    /**
     * Get test content from various sources
     */
    private function getTestContent(): ?string
    {
        // Try document ID first
        if ($documentId = $this->option('document-id')) {
            $document = Document::find($documentId);
            if (!$document) {
                $this->error("Document with ID {$documentId} not found");
                return null;
            }

            $this->info("Using document: {$document->filename} (ID: {$documentId})");
            
            try {
                if (file_exists($document->file_path)) {
                    return file_get_contents($document->file_path);
                }
                return Storage::disk($document->disk ?? 'local')->get($document->file_path);
            } catch (\Exception $e) {
                $this->error("Could not read document file: " . $e->getMessage());
                return null;
            }
        }

        // Try file argument
        if ($file = $this->argument('file')) {
            if (!file_exists($file)) {
                $this->error("File not found: {$file}");
                return null;
            }

            $this->info("Using file: {$file}");
            return file_get_contents($file);
        }

        // Use test email if available
        if (file_exists('test-enhanced-vehicle.eml')) {
            $this->info("Using test file: test-enhanced-vehicle.eml");
            return file_get_contents('test-enhanced-vehicle.eml');
        }

        // Show available documents
        $this->info("Available email documents:");
        $documents = Document::where('mime_type', 'message/rfc822')
                            ->orWhere('filename', 'like', '%.eml')
                            ->limit(10)
                            ->get(['id', 'filename', 'created_at']);

        if ($documents->isEmpty()) {
            $this->warn("No email documents found in database");
            return null;
        }

        foreach ($documents as $doc) {
            $this->line("  ID {$doc->id}: {$doc->filename} ({$doc->created_at})");
        }

        $this->newLine();
        $this->info("Use: php artisan test:hybrid-extraction --document-id=X");
        
        return null;
    }

    /**
     * Display database statistics
     */
    private function displayDatabaseStats(VehicleDatabaseService $vehicleDb): void
    {
        $this->info('📊 Database Statistics:');
        
        try {
            $vehicleCount = \DB::table('vehicle_specs')->count();
            $wmiCount = \DB::table('vin_wmis')->count();
            $brands = $vehicleDb->getAllBrands();
            
            $this->line("  Vehicle specifications: {$vehicleCount}");
            $this->line("  WMI codes: {$wmiCount}");
            $this->line("  Unique brands: {$brands->count()}");
            
            if ($this->option('detail')) {
                $this->line("  Brands: " . $brands->take(10)->implode(', ') . 
                           ($brands->count() > 10 ? '...' : ''));
            }
        } catch (\Exception $e) {
            $this->warn("Could not fetch database stats: " . $e->getMessage());
        }
        
        $this->newLine();
    }

    /**
     * Display extraction results
     */
    private function displayResults(array $result, float $totalTime): void
    {
        $data = $result['data'];
        $metadata = $result['metadata'];

        // Header
        $this->info('✅ Extraction completed successfully!');
        $this->info("⏱️  Total time: " . round($totalTime * 1000, 2) . "ms");
        $this->newLine();

        // Metadata
        $this->info('🔍 Extraction Metadata:');
        $this->line("  Strategies used: " . implode(', ', $metadata['extraction_strategies']));
        $this->line("  Overall confidence: " . ($metadata['overall_confidence'] * 100) . "%");
        $this->line("  Database validated: " . ($metadata['database_validated'] ? 'Yes' : 'No'));
        
        if (!empty($metadata['strategy_times'])) {
            $this->line("  Strategy times:");
            foreach ($metadata['strategy_times'] as $strategy => $time) {
                $this->line("    {$strategy}: {$time}ms");
            }
        }

        if (!empty($metadata['confidence_scores'])) {
            $this->line("  Confidence scores:");
            foreach ($metadata['confidence_scores'] as $strategy => $confidence) {
                $this->line("    {$strategy}: " . ($confidence * 100) . "%");
            }
        }
        
        $this->newLine();

        // Vehicle Information
        if (!empty($data['vehicle'])) {
            $this->displayVehicleInfo($data['vehicle']);
        }

        // Contact Information
        if (!empty($data['contact'])) {
            $this->displayContactInfo($data['contact']);
        }

        // Shipment Information
        if (!empty($data['shipment'])) {
            $this->displayShipmentInfo($data['shipment']);
        }

        // Pricing Information
        if (!empty($data['pricing'])) {
            $this->displayPricingInfo($data['pricing']);
        }

        // Dates Information
        if (!empty($data['dates'])) {
            $this->displayDatesInfo($data['dates']);
        }

        // Validation Results
        if (!empty($data['final_validation'])) {
            $this->displayValidationInfo($data['final_validation']);
        }

        // Full JSON output if detailed
        if ($this->option('detail')) {
            $this->newLine();
            $this->info('📄 Full extraction result (JSON):');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        }
    }

    /**
     * Display vehicle information
     */
    private function displayVehicleInfo(array $vehicle): void
    {
        $this->info('🚗 Vehicle Information:');
        
        $fields = [
            'brand' => 'Brand',
            'model' => 'Model',
            'variant' => 'Variant',
            'year' => 'Year',
            'vin' => 'VIN',
            'condition' => 'Condition',
            'color' => 'Color',
            'engine_cc' => 'Engine (CC)',
            'fuel_type' => 'Fuel Type',
            'transmission' => 'Transmission',
            'weight_kg' => 'Weight (kg)',
            'mileage_km' => 'Mileage (km)'
        ];

        foreach ($fields as $key => $label) {
            if (!empty($vehicle[$key])) {
                $this->line("  {$label}: {$vehicle[$key]}");
            }
        }

        // Dimensions
        if (!empty($vehicle['dimensions'])) {
            $dims = $vehicle['dimensions'];
            $dimStr = '';
            if (!empty($dims['length_m'])) $dimStr .= $dims['length_m'] . 'm';
            if (!empty($dims['width_m'])) $dimStr .= ' × ' . $dims['width_m'] . 'm';
            if (!empty($dims['height_m'])) $dimStr .= ' × ' . $dims['height_m'] . 'm';
            if (!empty($dims['volume_m3'])) $dimStr .= ' (' . $dims['volume_m3'] . 'm³)';
            
            if ($dimStr) {
                $this->line("  Dimensions: {$dimStr}");
            }
        }

        // Database information
        if (!empty($vehicle['database_match'])) {
            $this->line("  ✅ Database Match: ID " . ($vehicle['database_id'] ?? 'Unknown'));
            
            if (!empty($vehicle['database_confidence'])) {
                $this->line("  Database Confidence: " . ($vehicle['database_confidence'] * 100) . "%");
            }
        }

        // VIN decoded information
        if (!empty($vehicle['vin_decoded'])) {
            $vinData = $vehicle['vin_decoded'];
            $this->line("  VIN Info: " . ($vinData['manufacturer'] ?? 'Unknown') . 
                       " (" . ($vinData['country'] ?? 'Unknown') . ")");
        }

        $this->newLine();
    }

    /**
     * Display contact information
     */
    private function displayContactInfo(array $contact): void
    {
        $this->info('👤 Contact Information:');
        
        foreach (['name', 'company', 'email', 'phone'] as $field) {
            if (!empty($contact[$field])) {
                $this->line("  " . ucfirst($field) . ": {$contact[$field]}");
            }
        }
        
        $this->newLine();
    }

    /**
     * Display shipment information
     */
    private function displayShipmentInfo(array $shipment): void
    {
        $this->info('🚢 Shipment Information:');
        
        if (!empty($shipment['origin']) && !empty($shipment['destination'])) {
            $this->line("  Route: {$shipment['origin']} → {$shipment['destination']}");
        } else {
            if (!empty($shipment['origin'])) $this->line("  Origin: {$shipment['origin']}");
            if (!empty($shipment['destination'])) $this->line("  Destination: {$shipment['destination']}");
        }

        if (!empty($shipment['origin_port'])) $this->line("  Loading Port: {$shipment['origin_port']}");
        if (!empty($shipment['destination_port'])) $this->line("  Discharge Port: {$shipment['destination_port']}");
        if (!empty($shipment['shipping_type'])) $this->line("  Method: " . ucfirst($shipment['shipping_type']));
        if (!empty($shipment['container_size'])) $this->line("  Container: {$shipment['container_size']}");
        
        $this->newLine();
    }

    /**
     * Display pricing information
     */
    private function displayPricingInfo(array $pricing): void
    {
        $this->info('💰 Pricing Information:');
        
        if (!empty($pricing['amount']) && !empty($pricing['currency'])) {
            $this->line("  Amount: {$pricing['currency']} " . number_format($pricing['amount'], 2));
        }
        
        if (!empty($pricing['incoterm'])) {
            $this->line("  Incoterm: {$pricing['incoterm']}");
        }
        
        $this->newLine();
    }

    /**
     * Display dates information
     */
    private function displayDatesInfo(array $dates): void
    {
        $this->info('📅 Timeline:');
        
        $dateLabels = [
            'pickup_date' => 'Pickup',
            'delivery_date' => 'Delivery',
            'etd' => 'ETD',
            'eta' => 'ETA'
        ];

        foreach ($dateLabels as $key => $label) {
            if (!empty($dates[$key])) {
                $this->line("  {$label}: {$dates[$key]}");
            }
        }
        
        $this->newLine();
    }

    /**
     * Display validation information
     */
    private function displayValidationInfo(array $validation): void
    {
        $this->info('✓ Validation Results:');
        
        $this->line("  Quality Score: " . ($validation['quality_score'] * 100) . "%");
        
        if (!empty($validation['completeness_score'])) {
            $this->line("  Completeness: " . ($validation['completeness_score'] * 100) . "%");
        }

        if (!empty($validation['warnings'])) {
            $this->warn("  Warnings:");
            foreach ($validation['warnings'] as $warning) {
                $this->line("    - {$warning}");
            }
        }
        
        $this->newLine();
    }

    /**
     * Run benchmark tests
     */
    private function runBenchmark(HybridExtractionPipeline $pipeline, string $content): void
    {
        $this->info('🏃 Running benchmark tests...');
        
        $iterations = 5;
        $times = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $pipeline->extract($content, 'email');
            $times[] = microtime(true) - $start;
            
            $this->line("  Run " . ($i + 1) . ": " . round($times[$i] * 1000, 2) . "ms");
        }
        
        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);
        
        $this->newLine();
        $this->info('📊 Benchmark Results:');
        $this->line("  Average: " . round($avgTime * 1000, 2) . "ms");
        $this->line("  Min: " . round($minTime * 1000, 2) . "ms");
        $this->line("  Max: " . round($maxTime * 1000, 2) . "ms");
        $this->line("  Runs: {$iterations}");
    }
}

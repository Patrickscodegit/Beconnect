<?php

namespace App\Console\Commands;

use App\Models\Intake;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use Illuminate\Console\Command;

class TestRobawsJsonField extends Command
{
    protected $signature = 'robaws:test-json-field {intake_id}';
    protected $description = 'Test if JSON field is properly sent to Robaws';

    public function handle(EnhancedRobawsIntegrationService $robawsService)
    {
        $intakeId = $this->argument('intake_id');
        $intake = Intake::with(['documents.extractions'])->find($intakeId);
        
        if (!$intake) {
            $this->error("Intake {$intakeId} not found");
            return 1;
        }
        
        $document = $intake->documents->first();
        if (!$document) {
            $this->error("No document found for intake {$intakeId}");
            return 1;
        }
        
        $extraction = $document->extractions->first();
        if (!$extraction) {
            $this->error("No extraction found for document");
            return 1;
        }
        
        $this->info("=== Extraction Data Analysis ===");
        $this->info("Intake ID: {$intake->id}");
        $this->info("Document ID: {$document->id}");
        $this->info("Extraction ID: {$extraction->id}");
        
        // Check document raw_json vs extraction raw_json
        $this->info("Document has raw_json: " . ($document->raw_json ? 'Yes' : 'No'));
        $this->info("Document raw_json length: " . strlen($document->raw_json ?? ''));
        $this->info("Extraction has raw_json: " . ($extraction->raw_json ? 'Yes' : 'No'));
        $this->info("Extraction raw_json length: " . strlen($extraction->raw_json ?? ''));
        
        // Get the data that the service would use (updated logic)
        $extractedData = null;
        $dataSource = 'unknown';
        
        if ($extraction && $extraction->raw_json) {
            $extractedData = $extraction->raw_json;
            $dataSource = 'extraction.raw_json';
        } elseif ($document->raw_json) {
            $extractedData = $document->raw_json;
            $dataSource = 'document.raw_json';
        } else {
            $extractedData = $document->extraction_data;
            $dataSource = 'document.extraction_data';
        }
        
        if (is_string($extractedData)) {
            $extractedData = json_decode($extractedData, true);
        }
        
        $this->info("\n=== Data Source Analysis ===");
        $this->info("Service would use data source: " . $dataSource);
        $this->info("Has JSON field in data: " . (isset($extractedData['JSON']) ? 'Yes' : 'No'));
        
        if (isset($extractedData['JSON'])) {
            $this->info("JSON field length: " . strlen($extractedData['JSON']));
            $this->info("JSON preview: " . substr($extractedData['JSON'], 0, 100) . "...");
        }
        
        $this->info("Total fields in data: " . count($extractedData ?? []));
        $this->info("Available fields: " . implode(', ', array_slice(array_keys($extractedData ?? []), 0, 20)));
        
        // Test what payload would be sent to Robaws
        $this->info("\n=== Testing Robaws Integration ===");
        
        try {
            // Simulate the service call without actually creating an offer
            $user = $document->user ?? auth()->user();
            
            if (!$user) {
                $this->info("Creating test user for simulation...");
                $user = new \App\Models\User([
                    'name' => 'Test User',
                    'email' => 'test@example.com'
                ]);
            }
            
            $this->info("Test: Would create Robaws offer");
            $this->info("- Has JSON field: " . (isset($extractedData['JSON']) ? 'Yes' : 'No'));
            $this->info("- JSON field length: " . strlen($extractedData['JSON'] ?? ''));
            $this->info("- Data source: " . $dataSource);
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("Error testing Robaws integration: " . $e->getMessage());
            return 1;
        }
    }
}

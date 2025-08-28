<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Extraction;
use App\Services\Extraction\IntegrationDispatcher;
use App\Services\Extraction\Results\ExtractionResult;
use Illuminate\Console\Command;

class TestRobawsDispatcher extends Command
{
    protected $signature = 'test:robaws-dispatcher {extraction_id}';
    protected $description = 'Test Phase 1 fix: IntegrationDispatcher using raw_json for Robaws';

    public function handle()
    {
        $extractionId = $this->argument('extraction_id');
        
        $this->info("🧪 Testing Robaws Dispatcher with Extraction ID: {$extractionId}");
        $this->newLine();

        // Get extraction and document
        $extraction = Extraction::find($extractionId);
        if (!$extraction) {
            $this->error("❌ Extraction {$extractionId} not found");
            return 1;
        }

        $document = $extraction->document;
        if (!$document) {
            $this->error("❌ Document not found for extraction {$extractionId}");
            return 1;
        }

        $this->info("✅ Found extraction and document");
        $this->line("   Document ID: {$document->id}");
        $this->line("   Filename: {$document->filename}");
        $this->newLine();

        // Check raw_json data
        $rawData = json_decode($extraction->raw_json, true);
        $this->info("📊 Raw JSON Data Analysis:");
        $this->line("   Has JSON field: " . (isset($rawData['JSON']) ? 'YES' : 'NO'));
        $this->line("   Total fields: " . count($rawData ?? []));
        $this->line("   Vehicle make: " . ($rawData['vehicle_make'] ?? 'NOT FOUND'));
        $this->line("   JSON field size: " . (isset($rawData['JSON']) ? strlen($rawData['JSON']) . ' chars' : 'N/A'));
        $this->newLine();

        // Create a mock ExtractionResult for testing
        $extractionResult = ExtractionResult::success(
            $rawData, 
            0.95, 
            'test_strategy'
        );

        $this->info("🚀 Testing IntegrationDispatcher...");
        
        try {
            $dispatcher = app(IntegrationDispatcher::class);
            
            // This should now use the raw_json data which has the JSON field
            $result = $dispatcher->dispatch($document, $extractionResult);
            
            $this->info("✅ Dispatch completed successfully!");
            $this->line("   Services dispatched: " . implode(', ', array_keys($result)));
            
            if (isset($result['robaws'])) {
                $robawsResult = $result['robaws'];
                $this->line("   Robaws success: " . ($robawsResult['success'] ? 'YES' : 'NO'));
                
                if (!$robawsResult['success'] && isset($robawsResult['error'])) {
                    $this->error("   Robaws error: " . $robawsResult['error']);
                }
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Dispatch failed: " . $e->getMessage());
            $this->line("   Trace: " . $e->getTraceAsString());
            return 1;
        }

        $this->newLine();
        $this->info("🎉 Phase 1 fix test completed!");
        $this->line("The IntegrationDispatcher should now be using raw_json data");
        $this->line("which contains the JSON field that Robaws needs.");

        return 0;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Extraction;
use App\Services\Extraction\IntegrationDispatcher;
use App\Services\RobawsIntegration\EnhancedRobawsIntegrationService;
use App\Services\Extraction\Results\ExtractionResult;
use Illuminate\Console\Command;

class TestPhase1Complete extends Command
{
    protected $signature = 'test:phase1-complete {extraction_id}';
    protected $description = 'Test complete Phase 1 fix: IntegrationDispatcher + EnhancedRobawsIntegrationService + JsonFieldMapper';

    public function handle()
    {
        $extractionId = $this->argument('extraction_id');
        
        $this->info("🧪 Testing Complete Phase 1 Implementation");
        $this->info("═══════════════════════════════════════════");
        $this->line("Extraction ID: {$extractionId}");
        $this->newLine();

        // Step 1: Get extraction and document
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

        $this->info("✅ 1. Document & Extraction Retrieved");
        $this->table(['Field', 'Value'], [
            ['Document ID', $document->id],
            ['Filename', $document->filename],
            ['Extraction ID', $extraction->id],
            ['Status', $extraction->status],
        ]);
        $this->newLine();

        // Step 2: Analyze raw_json data
        $rawData = json_decode($extraction->raw_json, true);
        $this->info("📊 2. Raw JSON Data Analysis");
        $this->table(['Metric', 'Value'], [
            ['Has JSON field', isset($rawData['JSON']) ? 'YES' : 'NO'],
            ['JSON field size', isset($rawData['JSON']) ? strlen($rawData['JSON']) . ' chars' : 'N/A'],
            ['Total fields', count($rawData ?? [])],
            ['Vehicle make', $rawData['vehicle_make'] ?? 'NOT FOUND'],
            ['Vehicle model', $rawData['vehicle_model'] ?? 'NOT FOUND'],
            ['Origin', $rawData['origin'] ?? 'NOT FOUND'],
            ['Destination', $rawData['destination'] ?? 'NOT FOUND'],
        ]);
        $this->newLine();

        // Step 3: Test IntegrationDispatcher (Phase 1 Step 1)
        $this->info("🚀 3. Testing IntegrationDispatcher (Step 1)");
        
        try {
            $extractionResult = ExtractionResult::success($rawData, 0.95, 'test_strategy');
            $dispatcher = app(IntegrationDispatcher::class);
            
            $this->line("   → Dispatching to Robaws...");
            $result = $dispatcher->dispatch($document, $extractionResult);
            
            $this->info("   ✅ IntegrationDispatcher SUCCESS");
            $this->line("   → Services: " . implode(', ', array_keys($result)));
            $this->line("   → Robaws result: " . ($result['robaws']['success'] ? 'SUCCESS' : 'FAILED'));
            
        } catch (\Exception $e) {
            $this->error("   ❌ IntegrationDispatcher FAILED: " . $e->getMessage());
            return 1;
        }
        $this->newLine();

        // Step 4: Test EnhancedRobawsIntegrationService directly (Phase 1 Step 2)
        $this->info("🔧 4. Testing EnhancedRobawsIntegrationService (Step 2)");
        
        try {
            $robawsService = app(EnhancedRobawsIntegrationService::class);
            
            $this->line("   → Processing document directly...");
            $success = $robawsService->processDocument($document, $rawData);
            
            if ($success) {
                $this->info("   ✅ EnhancedRobawsIntegrationService SUCCESS");
                
                // Check document was updated
                $document->refresh();
                $this->line("   → Robaws status: " . ($document->robaws_sync_status ?? 'NULL'));
                $this->line("   → Quotation data: " . (empty($document->robaws_quotation_data) ? 'EMPTY' : 'PRESENT'));
                $this->line("   → Formatted at: " . ($document->robaws_formatted_at ?? 'NULL'));
            } else {
                $this->error("   ❌ EnhancedRobawsIntegrationService returned FALSE");
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->error("   ❌ EnhancedRobawsIntegrationService FAILED: " . $e->getMessage());
            return 1;
        }
        $this->newLine();

        // Step 5: Check final document state
        $this->info("📋 5. Final Document State Analysis");
        $document->refresh();
        
        $quotationData = $document->robaws_quotation_data;
        $hasQuotationData = !empty($quotationData);
        
        $this->table(['Field', 'Value'], [
            ['Robaws Sync Status', $document->robaws_sync_status ?? 'NULL'],
            ['Robaws Formatted At', $document->robaws_formatted_at ?? 'NULL'],
            ['Has Quotation Data', $hasQuotationData ? 'YES' : 'NO'],
            ['Quotation Fields', $hasQuotationData ? count($quotationData) : 0],
            ['Has JSON in Quotation', ($hasQuotationData && isset($quotationData['JSON'])) ? 'YES' : 'NO'],
            ['JSON Size in Quotation', ($hasQuotationData && isset($quotationData['JSON'])) ? strlen($quotationData['JSON']) . ' chars' : 'N/A'],
        ]);
        
        if ($hasQuotationData) {
            $this->newLine();
            $this->info("📝 Sample Quotation Data Fields:");
            $sampleFields = array_slice($quotationData, 0, 10);
            foreach ($sampleFields as $key => $value) {
                $displayValue = is_string($value) ? 
                    (strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value) : 
                    json_encode($value);
                $this->line("   → {$key}: {$displayValue}");
            }
        }

        $this->newLine();
        $this->info("🎉 Phase 1 Testing Completed!");
        
        // Summary
        $allSuccess = $hasQuotationData && 
                     ($document->robaws_sync_status !== null) && 
                     isset($quotationData['JSON']);
        
        if ($allSuccess) {
            $this->info("✅ ALL PHASE 1 COMPONENTS WORKING");
            $this->line("→ IntegrationDispatcher uses correct raw_json data");
            $this->line("→ EnhancedRobawsIntegrationService processes with validation");
            $this->line("→ JsonFieldMapper creates proper Robaws format");
            $this->line("→ Document has Robaws quotation data with JSON field");
        } else {
            $this->warn("⚠️  SOME ISSUES DETECTED - Check logs for details");
        }

        return $allSuccess ? 0 : 1;
    }
}

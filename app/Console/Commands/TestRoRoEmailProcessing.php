<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Extraction\HybridExtractionPipeline;
use App\Services\Export\Mappers\RobawsMapper;
use App\Models\Intake;

class TestRoRoEmailProcessing extends Command
{
    protected $signature = 'test:roro-email';
    protected $description = 'Test complete RO-RO email processing pipeline';

    public function handle()
    {
        $this->info('=== Testing Complete RO-RO Email Processing ===');
        $this->newLine();

        try {
            // 1. Read the RO-RO email file
            $emailPath = '/Users/patrickhome/Documents/Robaws2025_AI/Bconnect/RO-RO verscheping ANTWERPEN - MOMBASA, KENIA.eml';
            if (!file_exists($emailPath)) {
                $this->error('❌ RO-RO email file not found: ' . $emailPath);
                return 1;
            }

            $emailContent = file_get_contents($emailPath);
            $this->info('1. Email File:');
            $this->line('✓ Successfully read email file');
            $this->line('  Size: ' . strlen($emailContent) . ' bytes');
            $this->newLine();

            // 2. Create a mock intake
            $intake = new Intake();
            $intake->id = 999;
            $intake->customer_name = 'Test RO-RO Customer';
            $intake->customer_email = 'test@roro.com';
            $intake->status = 'pending';

            $this->info('2. Extraction Pipeline:');
            
            // 3. Run the extraction pipeline
            $pipeline = app(HybridExtractionPipeline::class);
            $extractedData = $pipeline->extract($emailContent);
            
            // Check if dimensions were found
            if (isset($extractedData['vehicle']['dimensions'])) {
                $dims = $extractedData['vehicle']['dimensions'];
                $this->info('✅ Dimensions extracted successfully:');
                $this->line('  Length: ' . ($dims['length_m'] ?? 'N/A') . 'm');
                $this->line('  Width: ' . ($dims['width_m'] ?? 'N/A') . 'm'); 
                $this->line('  Height: ' . ($dims['height_m'] ?? 'N/A') . 'm');
            } else {
                $this->error('❌ No dimensions found in extraction');
            }
            $this->newLine();

            // 4. Map to Robaws format
            $this->info('3. Robaws Mapping:');
            $robawsMapper = new RobawsMapper();
            $mappedData = $robawsMapper->mapIntakeToRobaws($intake, $extractedData);
            
            // Check cargo details
            if (isset($mappedData['cargo_details']['dimensions_text'])) {
                $this->info('✅ dimensions_text generated:');
                $this->line('  "' . $mappedData['cargo_details']['dimensions_text'] . '"');
            } else {
                $this->error('❌ dimensions_text not generated');
            }
            $this->newLine();

            // 5. Convert to API payload
            $this->info('4. API Payload Conversion:');
            $apiPayload = $robawsMapper->toRobawsApiPayload($mappedData);
            
            // Check final DIM_BEF_DELIVERY field
            if (isset($apiPayload['extraFields']['DIM_BEF_DELIVERY']['stringValue'])) {
                $dimValue = $apiPayload['extraFields']['DIM_BEF_DELIVERY']['stringValue'];
                $this->info('✅ SUCCESS! DIM_BEF_DELIVERY field populated:');
                $this->line('  "' . $dimValue . '"');
                $this->newLine();
                $this->info('🎉 COMPLETE SUCCESS! The RO-RO dimension extraction and mapping pipeline is working correctly!');
            } else {
                $this->error('❌ DIM_BEF_DELIVERY field not populated in final API payload');
            }

        } catch (\Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
            $this->line('Trace: ' . $e->getTraceAsString());
        }

        return 0;
    }
}

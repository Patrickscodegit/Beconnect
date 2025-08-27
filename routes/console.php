<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\AiRouter;
use App\Helpers\FileInput;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('test:ai-extraction', function () {
    $this->info('Testing AI Extraction...');
    
    try {
        $aiRouter = app(AiRouter::class);
        
        // Test with sample bytes input (simulating local development)
        $sampleInput = FileInput::forExtractor('test/sample.png', 'image/png');
        
        $this->info('Input type: ' . (isset($sampleInput['url']) ? 'URL' : 'bytes'));
        
        $result = $aiRouter->extractAdvanced($sampleInput, 'basic');
        
        $this->info('Extraction completed successfully!');
        $this->line('Document Type: ' . ($result['document_type'] ?? 'N/A'));
        $this->line('Status: ' . ($result['status'] ?? 'N/A'));
        $this->line('Processing Notes: ' . ($result['processing_notes'] ?? 'N/A'));
        
        if (isset($result['extracted_fields'])) {
            $this->info('Extracted Fields:');
            foreach ($result['extracted_fields'] as $key => $value) {
                $this->line("  {$key}: " . (is_string($value) ? $value : json_encode($value)));
            }
        }
        
    } catch (\Exception $e) {
        $this->error('AI extraction failed: ' . $e->getMessage());
        $this->line('Trace: ' . $e->getTraceAsString());
    }
})->purpose('Test AI extraction with sample data');

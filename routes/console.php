<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\AiRouter;
use App\Helpers\FileInput;
use App\Jobs\UpdateVehicleDatabaseJob;
use App\Jobs\UpdateShippingSchedulesJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('test:ai-extraction', function () {
    $this->info('Testing AI Extraction...');
    
    try {
        $aiRouter = app(AiRouter::class);
        
        // Use one of the available PNG files - use storage path format
        $storagePath = 'private/documents/68ae8b339dd6a_01K3MWZGDDVPTAB4ZAR3Y7Q830.png';
        $fullPath = storage_path('app/' . $storagePath);
        
        if (!file_exists($fullPath)) {
            $this->error('Test image not found at: ' . $fullPath);
            return;
        }
        
        $sampleInput = FileInput::forExtractor($storagePath, 'image/png');
        
        $this->info('Input type: ' . (isset($sampleInput['url']) ? 'URL' : 'bytes'));
        $this->info('Using file: ' . basename($storagePath));
        $this->info('Input details: ' . json_encode(array_map(function($v) {
            return is_string($v) && strlen($v) > 100 ? substr($v, 0, 100) . '...' : $v;
        }, $sampleInput)));
        
        $result = $aiRouter->extractAdvanced($sampleInput, 'shipping');
        
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
})->purpose('Test AI extraction with WhatsApp shipping image');

// Schedule vehicle database maintenance weekly
Schedule::job(new UpdateVehicleDatabaseJob())->weekly()->sundays()->at('02:00');

// Schedule shipping schedule updates daily
Schedule::job(new UpdateShippingSchedulesJob())->daily()->at('03:00');

// Schedule Robaws articles incremental sync daily at 3 AM (only syncs changed articles)
Schedule::command('robaws:sync-articles --incremental')->dailyAt('03:00');

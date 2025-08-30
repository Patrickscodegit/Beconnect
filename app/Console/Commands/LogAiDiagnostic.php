<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class LogAiDiagnostic extends Command
{
    protected $signature = 'ai:log-diagnostic';
    protected $description = 'Run AI diagnostic and log results to Laravel log';

    public function handle()
    {
        // Capture the output of ai:diagnose
        $this->call('ai:diagnose');
        
        // Log the results
        Log::info('AI Diagnostic completed', [
            'timestamp' => now(),
            'environment' => app()->environment(),
            'openai_key_set' => config('services.openai.api_key') ? 'Yes' : 'No',
            'anthropic_key_set' => config('services.anthropic.api_key') ? 'Yes' : 'No',
            'primary_service' => config('ai.primary_service'),
            'fallback_service' => config('ai.fallback_service'),
            'config_cached' => app()->configurationIsCached() ? 'Yes' : 'No'
        ]);
        
        // Test extraction and log result
        try {
            $this->call('ai:test-extraction');
            Log::info('AI Extraction test: SUCCESS');
        } catch (\Exception $e) {
            Log::error('AI Extraction test: FAILED', ['error' => $e->getMessage()]);
        }
        
        $this->info('✅ Diagnostic logged to Laravel log file');
        $this->info('Check storage/logs/laravel.log for results');
        
        return 0;
    }
}

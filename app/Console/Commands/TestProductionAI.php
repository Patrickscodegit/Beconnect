<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AiRouter;

class TestProductionAI extends Command
{
    protected $signature = 'ai:test-production';
    protected $description = 'Test AI configuration in production environment';

    public function handle()
    {
        $this->info('🔍 Testing production AI configuration...');
        
        // Check environment
        $this->line("Environment: " . app()->environment());
        $this->line("Debug mode: " . (config('app.debug') ? 'ON' : 'OFF'));
        
        // Test API keys are loaded
        $openaiKey = config('services.openai.api_key');
        $anthropicKey = config('services.anthropic.api_key');
        
        $this->line("OpenAI key loaded: " . ($openaiKey ? '✅ Yes' : '❌ No'));
        $this->line("Anthropic key loaded: " . ($anthropicKey ? '✅ Yes' : '❌ No'));
        
        if (!$openaiKey || !$anthropicKey) {
            $this->error('❌ API keys not properly loaded!');
            return 1;
        }
        
        // Test extraction (without exposing keys in logs)
        try {
            $ai = app(AiRouter::class);
            $result = $ai->extract('Test production extraction', [], ['cheap' => true]);
            $this->info('✅ Production AI extraction working correctly!');
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Production AI test failed: ' . $e->getMessage());
            return 1;
        }
    }
}

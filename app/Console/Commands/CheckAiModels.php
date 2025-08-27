<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckAiModels extends Command
{
    protected $signature = 'check:ai-models';
    protected $description = 'Check which AI models are configured and used';

    public function handle()
    {
        $this->info('=== AI Models Configuration Check ===');
        
        // Environment variables
        $this->info("\n1. Environment Variables:");
        $this->line('OPENAI_MODEL: ' . (env('OPENAI_MODEL') ?? 'not set'));
        $this->line('OPENAI_MODEL_CHEAP: ' . (env('OPENAI_MODEL_CHEAP') ?? 'not set'));
        $this->line('OPENAI_VISION_MODEL: ' . (env('OPENAI_VISION_MODEL') ?? 'not set'));
        
        // Config values
        $this->info("\n2. Config Values:");
        $cfg = config('services.openai');
        $this->line('Main model: ' . ($cfg['model'] ?? 'not set'));
        $this->line('Cheap model: ' . ($cfg['model_cheap'] ?? 'not set'));
        $this->line('Vision model: ' . ($cfg['vision_model'] ?? 'not set'));
        
        // Model capabilities check
        $this->info("\n3. Model Capabilities:");
        $models = [
            'gpt-4o' => 'Vision + Text (Latest, recommended for vision)',
            'gpt-4o-mini' => 'Vision + Text (Faster, cheaper)',
            'gpt-4-turbo-preview' => 'Text only (NO vision capabilities)',
            'gpt-4-vision-preview' => 'Vision + Text (Deprecated)',
        ];
        
        foreach ($models as $model => $capabilities) {
            $this->line("$model: $capabilities");
        }
        
        // Check what's actually being used
        $this->info("\n4. Models Used in Code:");
        $this->line('AiRouter vision: ' . ($cfg['vision_model'] ?? 'gpt-4o'));
        $this->line('LlmExtractor: ' . config('services.openai.model', 'gpt-4-turbo-preview'));
        
        // Recommendations
        $this->info("\n5. Recommendations:");
        $visionModel = $cfg['vision_model'] ?? 'gpt-4o';
        if (str_contains($visionModel, 'gpt-4o')) {
            $this->info('✅ Vision model is correct: ' . $visionModel);
        } else {
            $this->error('❌ Vision model may not support vision: ' . $visionModel);
        }
        
        $textModel = config('services.openai.model', 'gpt-4-turbo-preview');
        if ($textModel === 'gpt-4-turbo-preview') {
            $this->warn('⚠️  LlmExtractor uses gpt-4-turbo-preview (no vision)');
        } else {
            $this->info('✅ Text model: ' . $textModel);
        }
        
        return 0;
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class DiagnoseAiConfig extends Command
{
    protected $signature = 'ai:diagnose';
    protected $description = 'Diagnose AI configuration and environment issues';

    public function handle()
    {
        $this->info('🔍 AI Configuration Diagnostic Report');
        $this->info('====================================');
        $this->newLine();
        
        // Environment info
        $this->info('📍 Environment Information:');
        $this->line('  Environment: ' . app()->environment());
        $this->line('  Debug Mode: ' . (config('app.debug') ? 'ON' : 'OFF'));
        $this->line('  PHP Version: ' . PHP_VERSION);
        $this->newLine();
        
        // Check OpenAI config
        $this->info('🤖 OpenAI Configuration:');
        $openaiKey = config('services.openai.api_key');
        $this->line('  API Key: ' . ($openaiKey ? '✅ Set (' . strlen($openaiKey) . ' chars)' : '❌ NOT SET'));
        $this->line('  Model: ' . (config('services.openai.model') ?: '❌ NOT SET'));
        $this->line('  Model Cheap: ' . (config('services.openai.model_cheap') ?: '❌ NOT SET'));
        $this->line('  Timeout: ' . (config('services.openai.timeout') ?: '❌ NOT SET'));
        $this->newLine();
        
        // Check Anthropic config
        $this->info('🧠 Anthropic Configuration:');
        $anthropicKey = config('services.anthropic.api_key');
        $this->line('  API Key: ' . ($anthropicKey ? '✅ Set (' . strlen($anthropicKey) . ' chars)' : '❌ NOT SET'));
        $this->line('  Model: ' . (config('services.anthropic.model') ?: '❌ NOT SET'));
        $this->line('  Version: ' . (config('services.anthropic.version') ?: '❌ NOT SET'));
        $this->newLine();
        
        // Check AI service config
        $this->info('⚙️  AI Service Configuration:');
        $this->line('  Primary Service: ' . (config('ai.primary_service') ?: '❌ NOT SET'));
        $this->line('  Fallback Service: ' . (config('ai.fallback_service') ?: '❌ NOT SET'));
        $this->line('  Cache Enabled: ' . (config('ai.performance.cache_enabled') ? '✅ Yes' : '❌ No'));
        $this->line('  Cheap Max Input: ' . (config('ai.routing.cheap_max_input_tokens') ?: '❌ NOT SET'));
        $this->newLine();
        
        // Check config files exist
        $this->info('📁 Configuration Files:');
        $this->line('  config/ai.php: ' . (file_exists(config_path('ai.php')) ? '✅ Exists' : '❌ Missing'));
        $this->line('  config/services.php: ' . (file_exists(config_path('services.php')) ? '✅ Exists' : '❌ Missing'));
        $this->line('  .env file: ' . (file_exists(base_path('.env')) ? '✅ Exists' : '❌ Missing'));
        $this->newLine();
        
        // Check environment variables directly
        $this->info('🌍 Environment Variables (Raw):');
        $this->line('  OPENAI_API_KEY: ' . (env('OPENAI_API_KEY') ? '✅ Set' : '❌ NOT SET'));
        $this->line('  ANTHROPIC_API_KEY: ' . (env('ANTHROPIC_API_KEY') ? '✅ Set' : '❌ NOT SET'));
        $this->line('  PRIMARY_SERVICE: ' . (env('PRIMARY_SERVICE') ?: '❌ NOT SET'));
        $this->line('  FALLBACK_SERVICE: ' . (env('FALLBACK_SERVICE') ?: '❌ NOT SET'));
        $this->newLine();
        
        // Check cache status
        $this->info('💾 Cache Status:');
        $this->line('  Config cached: ' . (app()->configurationIsCached() ? '✅ Yes' : '❌ No'));
        $this->line('  Routes cached: ' . (app()->routesAreCached() ? '✅ Yes' : '❌ No'));
        $this->newLine();
        
        // Check if AiRouter service is available
        $this->info('🔧 Service Availability:');
        try {
            $aiRouter = app(\App\Services\AiRouter::class);
            $this->line('  AiRouter Service: ✅ Available');
        } catch (\Exception $e) {
            $this->line('  AiRouter Service: ❌ Error - ' . $e->getMessage());
        }
        $this->newLine();
        
        // Recommendations
        $this->info('💡 Recommendations:');
        if (!config('services.openai.api_key') && !config('services.anthropic.api_key')) {
            $this->warn('  ⚠️  No AI API keys found! Add OPENAI_API_KEY and ANTHROPIC_API_KEY to .env');
        }
        if (app()->configurationIsCached()) {
            $this->warn('  ⚠️  Config is cached. Run "php artisan config:clear" after changes');
        }
        if (!config('ai.primary_service')) {
            $this->warn('  ⚠️  PRIMARY_SERVICE not set. Add PRIMARY_SERVICE=openai to .env');
        }
        
        $this->newLine();
        $this->info('🎯 Next step: Run "php artisan ai:test" to test extraction');
        
        return 0;
    }
}

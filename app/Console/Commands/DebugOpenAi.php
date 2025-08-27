<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DebugOpenAi extends Command
{
    protected $signature = 'debug:openai';
    protected $description = 'Debug OpenAI configuration';

    public function handle()
    {
        $this->info('=== OpenAI Configuration Debug ===');
        
        // Check environment variable
        $envKey = env('OPENAI_API_KEY');
        $this->line('Environment OPENAI_API_KEY: ' . ($envKey ? 'SET (' . substr($envKey, 0, 20) . '...)' : 'NOT SET'));
        
        // Check config
        $cfg = config('services.openai');
        $this->line('Config loaded: ' . (is_array($cfg) ? 'YES' : 'NO'));
        
        if (is_array($cfg)) {
            $this->line('Config keys: ' . implode(', ', array_keys($cfg)));
            $this->line('API key in config: ' . (isset($cfg['api_key']) && !empty($cfg['api_key']) ? 'SET (' . substr($cfg['api_key'], 0, 20) . '...)' : 'NOT SET'));
        }
        
        // Test OpenAI client creation
        try {
            if (!empty($cfg['api_key'])) {
                $client = \OpenAI::client($cfg['api_key']);
                $this->info('✅ OpenAI client created successfully');
            } else {
                $this->error('❌ Cannot create OpenAI client - no API key');
            }
        } catch (\Exception $e) {
            $this->error('❌ OpenAI client creation failed: ' . $e->getMessage());
        }
        
        return 0;
    }
}

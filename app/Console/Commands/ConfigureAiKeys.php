<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ConfigureAiKeys extends Command
{
    protected $signature = 'ai:configure';
    protected $description = 'Interactively configure AI keys & defaults in .env';

    public function handle(): int
    {
        $this->info('🚀 Configure AI keys for OpenAI and Anthropic');
        $this->newLine();

        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            $this->error('.env not found. Copy .env.example to .env first.');
            return self::FAILURE;
        }

        $pairs = [];

        // Primary service selection
        $pairs['PRIMARY_SERVICE'] = $this->choice(
            'Primary AI service', 
            ['openai', 'anthropic'], 
            env('PRIMARY_SERVICE', 'openai')
        );

        $this->newLine();
        $this->info('🔑 OpenAI Configuration');

        // OpenAI configuration
        $currentOpenAiKey = env('OPENAI_API_KEY');
        if ($currentOpenAiKey) {
            $this->line("Current OpenAI API Key: " . substr($currentOpenAiKey, 0, 7) . '...' . substr($currentOpenAiKey, -4));
            if (!$this->confirm('Update OpenAI API Key?', false)) {
                $pairs['OPENAI_API_KEY'] = $currentOpenAiKey;
            } else {
                $pairs['OPENAI_API_KEY'] = $this->secret('OPENAI_API_KEY (sk-...)');
            }
        } else {
            $pairs['OPENAI_API_KEY'] = $this->secret('OPENAI_API_KEY (sk-...)');
        }

        $pairs['OPENAI_MODEL'] = $this->ask(
            'OPENAI_MODEL (heavy model)', 
            env('OPENAI_MODEL', 'gpt-4o')
        );
        
        $pairs['OPENAI_MODEL_CHEAP'] = $this->ask(
            'OPENAI_MODEL_CHEAP (fast model)', 
            env('OPENAI_MODEL_CHEAP', 'gpt-4o-mini')
        );

        // Anthropic configuration
        if ($this->confirm('Configure Anthropic as fallback?', true)) {
            $this->newLine();
            $this->info('🔑 Anthropic Configuration');

            $currentAnthropicKey = env('ANTHROPIC_API_KEY');
            if ($currentAnthropicKey) {
                $this->line("Current Anthropic API Key: " . substr($currentAnthropicKey, 0, 7) . '...' . substr($currentAnthropicKey, -4));
                if (!$this->confirm('Update Anthropic API Key?', false)) {
                    $pairs['ANTHROPIC_API_KEY'] = $currentAnthropicKey;
                } else {
                    $pairs['ANTHROPIC_API_KEY'] = $this->secret('ANTHROPIC_API_KEY (sk-ant-...)');
                }
            } else {
                $pairs['ANTHROPIC_API_KEY'] = $this->secret('ANTHROPIC_API_KEY (sk-ant-...)');
            }

            $pairs['ANTHROPIC_MODEL'] = $this->ask(
                'ANTHROPIC_MODEL', 
                env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-20241022')
            );

            $pairs['FALLBACK_SERVICE'] = 'anthropic';
        }

        // Performance settings
        if ($this->confirm('Configure performance settings?', true)) {
            $this->newLine();
            $this->info('⚡ Performance Configuration');

            $pairs['AI_CACHE_ENABLED'] = $this->confirm('Enable caching?', true) ? 'true' : 'false';
            $pairs['AI_CONTENT_TRIM_ENABLED'] = $this->confirm('Enable content trimming?', true) ? 'true' : 'false';
            $pairs['AI_PARALLEL_ENABLED'] = $this->confirm('Enable parallel processing?', true) ? 'true' : 'false';
            
            $pairs['AI_CHEAP_MAX_INPUT'] = $this->ask(
                'Max input tokens for cheap model', 
                env('AI_CHEAP_MAX_INPUT', '8000')
            );
        }

        $this->newLine();
        $this->info('Writing configuration to .env...');

        $this->writeEnv($envPath, $pairs);

        $this->newLine();
        $this->info('✅ Configuration updated successfully!');
        $this->warn('Run the following commands to apply changes:');
        $this->line('php artisan config:clear');
        $this->line('php artisan config:cache');
        $this->newLine();
        $this->info('Test your configuration with:');
        $this->line('php artisan ai:test');

        return self::SUCCESS;
    }

    protected function writeEnv(string $path, array $pairs): void
    {
        $env = file_get_contents($path);
        
        foreach ($pairs as $key => $val) {
            $val = $val ?: '';
            $line = $key.'='.(str_contains((string)$val, ' ') ? '"'.$val.'"' : $val);
            
            if (preg_match("/^{$key}=.*$/m", $env)) {
                $env = preg_replace("/^{$key}=.*$/m", $line, $env);
            } else {
                $env .= "\n{$line}";
            }
        }
        
        file_put_contents($path, $env);
    }
}

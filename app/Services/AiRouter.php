<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class AiRouter
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Extract structured data from text (optionally with a JSON schema).
     *
     * @param  string $text Raw text (already OCR'd) or concatenated email body.
     * @param  array  $schema Optional JSON Schema (draft-07 style) for structured outputs.
     * @param  array  $options [service, model, cheap(bool), reasoning(bool), temperature(float)]
     * @return array Decoded JSON result (throws on failure)
     */
    public function extract(string $text, array $schema = [], array $options = []): array
    {
        // Content-based caching
        $cacheKey = 'ai_extract:' . md5($text . json_encode($schema) . json_encode($options));
        
        if (config('ai.performance.cache_enabled', true)) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                $this->logger->info('AI extraction cache hit', ['cache_key' => $cacheKey]);
                return $cached;
            }
        }

        // Trim content if enabled
        if (config('ai.performance.content_trim_enabled', true)) {
            $text = $this->trimContent($text);
        }

        $service = $options['service'] ?? config('ai.primary_service', 'openai');

        // Decide cheap vs heavy model
        $inputTokens = $this->estimateTokens($text);
        $useCheap = $options['cheap'] ?? ($inputTokens <= (int)config('ai.routing.cheap_max_input_tokens'));
        if (!empty($options['reasoning']) && config('ai.routing.reasoning_force_heavy')) {
            $useCheap = false;
        }

        $startTime = microtime(true);

        try {
            if ($service === 'anthropic') {
                $result = $this->anthropicExtract($text, $schema, $useCheap, $options);
            } else {
                // default: openai
                $result = $this->openAiExtract($text, $schema, $useCheap, $options);
            }

            $duration = microtime(true) - $startTime;
            
            $this->logger->info('AI extraction completed', [
                'service' => $service,
                'model' => $useCheap ? 'cheap' : 'heavy',
                'input_tokens' => $inputTokens,
                'duration_seconds' => round($duration, 2),
            ]);

            // Cache successful results
            if (config('ai.performance.cache_enabled', true)) {
                Cache::put($cacheKey, $result, config('ai.performance.cache_ttl', 3600));
            }

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('AI extraction failed, trying fallback', [
                'primary_service' => $service,
                'error' => $e->getMessage()
            ]);

            // Try fallback service
            $fallbackService = config('ai.fallback_service');
            if ($fallbackService && $fallbackService !== $service) {
                return $this->extract($text, $schema, array_merge($options, ['service' => $fallbackService]));
            }

            throw $e;
        }
    }

    /** Rough token estimator (~4 chars/token heuristic). */
    protected function estimateTokens(string $s): int
    {
        return (int) ceil(mb_strlen($s, 'UTF-8') / 4);
    }

    /**
     * Trim unnecessary content to reduce tokens
     */
    protected function trimContent(string $text): string
    {
        // Remove multiple spaces/newlines
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Remove common headers/footers patterns
        $patterns = [
            '/Page \d+ of \d+/i',
            '/Confidential.*?©.*?\d{4}/is',
            '/\[?Header\]?.*?\[?\/Header\]?/is',
            '/\[?Footer\]?.*?\[?\/Footer\]?/is',
        ];
        
        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }
        
        return trim($text);
    }

    /**
     * Dynamic output token calculation based on schema
     */
    protected function calculateMaxTokens(array $schema): int
    {
        $fields = count(data_get($schema, 'properties', []));
        $baseTokens = 300;
        $perFieldTokens = 50;
        $maxTokens = config('services.openai.max_output_tokens', 900);
        
        return max($baseTokens, min($maxTokens, $fields * $perFieldTokens + 100));
    }

    protected function openAiExtract(string $text, array $schema, bool $cheap, array $options): array
    {
        $cfg     = config('services.openai');
        $model   = $options['model'] ?? ($cheap ? $cfg['model_cheap'] : $cfg['model']);
        $timeout = (int)($cfg['timeout'] ?? 20);
        
        // Dynamic token calculation
        $maxTokens = $this->calculateMaxTokens($schema);

        $system = 'You are a precise logistics data extractor. '
                . 'Return ONLY valid JSON that matches the requested schema. '
                . 'Be concise. No prose, no explanations.';

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $text],
            ],
            'temperature' => (float)($options['temperature'] ?? 0.1),
            'max_tokens'  => $maxTokens,
        ];

        // Try Structured Outputs if supported by your model
        if (!empty($schema)) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'extraction',
                    'schema' => $schema,
                    'strict' => true,
                ],
            ];
        }

        $resp = Http::withToken($cfg['api_key'])
            ->timeout($timeout)
            ->retry(1, 100) // Single retry with 100ms delay
            ->baseUrl(rtrim((string)$cfg['base_url'], '/'))
            ->post('/chat/completions', $payload)
            ->throw();

        $content = data_get($resp->json(), 'choices.0.message.content');
        
        // Log token usage for cost tracking
        $usage = data_get($resp->json(), 'usage');
        if ($usage) {
            $this->logUsage('openai', $model, $usage['prompt_tokens'], $usage['completion_tokens']);
        }

        return $this->ensureJson($content);
    }

    protected function anthropicExtract(string $text, array $schema, bool $cheap, array $options): array
    {
        $cfg     = config('services.anthropic');
        $model   = $options['model'] ?? $cfg['model']; // if you add a cheap model later, choose here
        $timeout = (int)($cfg['timeout'] ?? 20);

        // For maximum compatibility we instruct JSON strictly in the prompt.
        $prompt = "Return ONLY JSON matching the schema (if provided). No prose, no markdown.\n\nText:\n" . $text;
        if (!empty($schema)) {
            $prompt = "Schema (JSON Schema):\n" . json_encode($schema) . "\n\n" . $prompt;
        }

        $resp = Http::withHeaders([
                'x-api-key' => $cfg['api_key'],
                'anthropic-version' => $cfg['version'] ?? '2023-06-01',
                'content-type' => 'application/json',
            ])
            ->timeout($timeout)
            ->retry(1, 100) // Single retry with 100ms delay
            ->baseUrl(rtrim((string)$cfg['base_url'], '/'))
            ->post('/messages', [
                'model' => $model,
                'max_tokens' => (int)($cfg['max_output_tokens'] ?? 900),
                'temperature' => (float)($options['temperature'] ?? 0.1),
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ])
            ->throw();

        // Anthropics returns content as an array of blocks; take first text block
        $content = data_get($resp->json(), 'content.0.text');
        
        // Log token usage for cost tracking
        $usage = data_get($resp->json(), 'usage');
        if ($usage) {
            $this->logUsage('anthropic', $model, $usage['input_tokens'], $usage['output_tokens']);
        }

        return $this->ensureJson($content);
    }

    /** Ensure string is valid JSON; attempt to salvage if model wrapped it. */
    protected function ensureJson(?string $s): array
    {
        $s = trim((string)$s);
        // quick salvage if surrounded by code fences
        if (Str::startsWith($s, '```')) {
            $s = preg_replace('/^```[a-zA-Z]*\n|\n```$/', '', $s);
        }
        $decoded = json_decode($s, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        // try to extract the first {...} block
        if (preg_match('/\{[\s\S]*\}/', $s, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
        throw new \RuntimeException('AI response was not valid JSON. Raw: '.Str::limit($s, 400));
    }

    /**
     * Log usage for cost tracking and monitoring
     */
    protected function logUsage(string $service, string $model, int $inputTokens, int $outputTokens): void
    {
        $pricing = config("ai.pricing_per_million.{$service}.{$model}");
        if ($pricing) {
            $cost = ($inputTokens * $pricing['input'] / 1_000_000) + 
                    ($outputTokens * $pricing['output'] / 1_000_000);
            
            $this->logger->info('AI Usage', [
                'service' => $service,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'estimated_cost_usd' => round($cost, 4),
            ]);
        }
    }
}

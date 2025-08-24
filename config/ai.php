<?php

return [
    'primary_service' => env('PRIMARY_SERVICE', 'openai'), // 'openai' or 'anthropic'
    'fallback_service'=> env('FALLBACK_SERVICE', 'anthropic'),

    // Simple routing thresholds
    'routing' => [
        'cheap_max_input_tokens' => env('AI_CHEAP_MAX_INPUT', 8000), // use cheap model below this size
        'reasoning_force_heavy'  => env('AI_REASONING_FORCE_HEAVY', true),
    ],

    // Updated pricing for current models (per million tokens)
    'pricing_per_million' => [
        'openai' => [
            'gpt-4o'      => ['input' => 2.50, 'output' => 10.00],
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        ],
        'anthropic' => [
            'claude-3-5-sonnet-20241022' => ['input' => 3.00, 'output' => 15.00],
        ],
    ],

    // Performance settings
    'performance' => [
        'cache_enabled' => env('AI_CACHE_ENABLED', true),
        'cache_ttl' => env('AI_CACHE_TTL', 3600),
        'content_trim_enabled' => env('AI_CONTENT_TRIM_ENABLED', true),
        'parallel_processing_enabled' => env('AI_PARALLEL_ENABLED', true),
        'max_pages_parallel' => env('AI_MAX_PAGES_PARALLEL', 8),
    ],
];

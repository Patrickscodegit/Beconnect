<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Schedule Extraction Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for AI-powered schedule extraction and validation.
    | This allows for enhanced parsing of complex shipping schedules.
    |
    */

    // Enable AI-powered schedule parsing
    'use_ai_parsing' => env('USE_AI_SCHEDULE_PARSING', false),

    // Enable AI validation of extracted schedules
    'use_ai_validation' => env('USE_AI_VALIDATION', false),

    // OpenAI API configuration
    'openai_api_key' => env('OPENAI_API_KEY'),
    'openai_model' => env('OPENAI_MODEL', 'gpt-4'),

    // AI validation threshold (0.0 to 1.0)
    /*
    |--------------------------------------------------------------------------
    | AI Validation Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for AI-powered schedule validation
    |
    */

    'ai_validation_threshold' => env('AI_VALIDATION_THRESHOLD', 0.85),

    // Enable fallback to traditional parsing if AI fails
    'ai_fallback_enabled' => env('AI_FALLBACK_ENABLED', true),

    // Maximum number of schedules to validate with AI
    'max_schedules_for_ai_validation' => env('MAX_SCHEDULES_FOR_AI_VALIDATION', 20),

    // Timeout for AI API requests (seconds)
    'ai_request_timeout' => env('AI_REQUEST_TIMEOUT', 60),

    // Enable AI validation for specific routes only
    'ai_validation_routes' => [
        // Example: ['ANR', 'CKY'] for Antwerp to Conakry
    ],

    // Disable AI for specific routes (override global setting)
    'ai_disabled_routes' => [
        // Example: ['ZEE', 'LOS'] for Zeebrugge to Lagos
    ],

    // Logging configuration
    'log_ai_requests' => env('LOG_AI_REQUESTS', true),
    'log_ai_responses' => env('LOG_AI_RESPONSES', false),

    // Cost control
    'max_ai_requests_per_hour' => env('MAX_AI_REQUESTS_PER_HOUR', 100),
    'ai_cost_limit_per_day' => env('AI_COST_LIMIT_PER_DAY', 10.00),

    // Hybrid processing configuration
    'hybrid_processing_enabled' => env('HYBRID_PROCESSING_ENABLED', false),
    'hybrid_ai_threshold' => env('HYBRID_AI_THRESHOLD', 5), // Use AI if >5 schedules

    // Traditional parsing fallback
    'traditional_parsing_priority' => env('TRADITIONAL_PARSING_PRIORITY', true),
];



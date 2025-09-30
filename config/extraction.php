<?php

return [
    /**
     * EXTRACTION STRATEGY CONFIGURATION
     * 
     * This configuration allows you to control which extraction strategies
     * are used without breaking existing functionality.
     */

    /**
     * Strategy Selection Mode
     * 
     * 'isolated' - Use isolated strategies (recommended for stability)
     * 'shared' - Use shared strategies (legacy mode)
     * 'hybrid' - Use isolated for email, enhanced for PDF/Image
     */
    'strategy_mode' => env('EXTRACTION_STRATEGY_MODE', 'isolated'),

    /**
     * Use Isolated Strategies
     * 
     * When true, uses IsolatedEmailExtractionStrategy, EnhancedPdfExtractionStrategy,
     * and EnhancedImageExtractionStrategy. These are completely isolated from each other
     * and won't break when you enhance one strategy.
     */
    'use_isolated_strategies' => env('EXTRACTION_USE_ISOLATED_STRATEGIES', true),

    /**
     * Strategy Priorities
     * 
     * Higher numbers = higher priority
     * Email should always have the highest priority to ensure it works
     */
    'priorities' => [
        'email' => 100,
        'pdf' => 90,
        'image' => 80,
    ],

    /**
     * Isolation Settings
     */
    'isolation' => [
        'email_strategy' => 'isolated', // 'isolated' or 'shared'
        'pdf_strategy' => 'simple',     // 'simple' - consolidated to SimplePdfExtractionStrategy
        'image_strategy' => 'enhanced', // 'enhanced' or 'shared'
    ],

    /**
     * Enhancement Settings
     */
    'enhancement' => [
        'enable_ai_extraction' => env('USE_AI_EXTRACTION', true),
        'enable_pattern_extraction' => true,
        'enable_database_enhancement' => true,
        'enable_vehicle_enhancement' => env('EXTRACTION_ENABLE_VEHICLE_ENHANCEMENT', true),
    ],

    /**
     * Performance Settings
     */
    'performance' => [
        'cache_enabled' => env('AI_CACHE_ENABLED', true),
        'cache_ttl' => env('AI_CACHE_TTL', 3600),
        'parallel_processing' => env('AI_PARALLEL_ENABLED', true),
        'max_pages_parallel' => env('AI_MAX_PAGES_PARALLEL', 8),
    ],

    /**
     * Debug Settings
     */
    'debug' => [
        'enabled' => env('EXTRACT_DEBUG', false),
        'log_strategy_selection' => true,
        'log_isolation_status' => true,
        'log_enhancement_features' => true,
    ],

    /**
     * Strategy-Specific Settings
     */
    'strategies' => [
        'email' => [
            'use_hybrid_pipeline' => false, // Isolated strategy doesn't use shared pipeline
            'preserve_sender_contact' => true,
            'infer_company_from_email' => true,
            'mime_parser' => 'zbateson/mail-mime-parser',
        ],
        'pdf' => [
            'use_hybrid_pipeline' => false, // Simple strategy doesn't use shared pipeline
            'text_extraction_method' => 'pdf_service',
            'fallback_extraction' => true,
            'pattern_based_extraction' => true,
        ],
        'image' => [
            'use_hybrid_pipeline' => false, // Enhanced strategy doesn't use shared pipeline
            'vision_model' => env('AI_VISION_MODEL', 'gpt-4o'),
            'enhanced_post_processing' => true,
            'robaws_transform' => true,
        ],
    ],

    /**
     * Fallback Settings
     */
    'fallback' => [
        'enable_fallback_strategies' => true,
        'fallback_to_shared' => false, // Don't fallback to shared strategies
        'fallback_to_basic' => true,   // Fallback to basic extraction
    ],

    /**
     * Monitoring Settings
     */
    'monitoring' => [
        'track_strategy_usage' => true,
        'track_isolation_status' => true,
        'track_enhancement_features' => true,
        'log_performance_metrics' => true,
    ],
];
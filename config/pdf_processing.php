<?php

return [
    /**
     * OPTIMIZED PDF PROCESSING CONFIGURATION
     * 
     * Phase 1 optimizations for PDF extraction without caching.
     * Focuses on memory management, streaming processing, and intelligent method selection.
     */

    /**
     * Memory Management Settings
     */
    'memory' => [
        'max_memory_per_pdf' => '50MB',
        'streaming_threshold' => '5MB',
        'very_large_threshold' => '10MB',
        'temp_file_cleanup' => 'immediate',
        'gc_force_after_processing' => true,
        'memory_limit_mb' => 128,
        'warning_threshold_mb' => 64,
    ],

    /**
     * Processing Settings
     */
    'processing' => [
        'enable_streaming' => true,
        'enable_parallel_sections' => true,
        'enable_method_selection' => true,
        'enable_memory_monitoring' => true,
        'enable_early_termination' => true,
        'min_required_fields' => 2,
    ],

    /**
     * Method Selection Settings
     */
    'method_selection' => [
        'large_file_threshold' => 5_000_000, // 5MB
        'very_large_file_threshold' => 10_000_000, // 10MB
        'min_text_length' => 100,
        'complexity_thresholds' => [
            'low' => 0.7,    // text_density > 0.7, image_density < 0.3
            'medium' => 0.4, // text_density > 0.4, image_density < 0.6
            'high' => 0.0,   // everything else
        ],
    ],

    /**
     * Pattern Matching Settings
     */
    'pattern_matching' => [
        'enable_compiled_patterns' => true,
        'pattern_order' => [
            'contact_info' => 0.9,
            'vehicle_info' => 0.8,
            'routing_info' => 0.7,
            'cargo_info' => 0.6,
        ],
        'early_termination_threshold' => 2, // Stop after finding 2 required fields
    ],

    /**
     * Temporary File Management
     */
    'temp_files' => [
        'cleanup_on_destruct' => true,
        'max_temp_files' => 1,
        'temp_dir_prefix' => 'pdf_processing_',
        'enable_cleanup_logging' => true,
    ],

    /**
     * Debugging Settings (for testing phase)
     */
    'debugging' => [
        'log_memory_usage' => true,
        'log_processing_time' => true,
        'log_method_selection' => true,
        'log_pattern_matches' => true,
        'log_temp_file_operations' => true,
        'log_pdf_analysis' => true,
    ],

    /**
     * Performance Limits
     */
    'limits' => [
        'max_file_size_mb' => 50,
        'max_processing_time_seconds' => 60,
        'max_memory_mb' => 128,
        'max_temp_files' => 1,
        'max_page_count' => 50,
    ],

    /**
     * Extraction Methods
     */
    'methods' => [
        'streaming' => [
            'enabled' => true,
            'command' => 'pdftotext -layout "%s" "%s/extracted_text.txt"',
            'fallback_to_ocr' => true,
        ],
        'pdfparser' => [
            'enabled' => true,
            'fallback_to_ocr' => true,
        ],
        'ocr_direct' => [
            'enabled' => true,
            'gs_command' => 'gs -dNOPAUSE -dBATCH -sDEVICE=png16m -r150 -sOutputFile="%s/page_%d.png" "%s"',
            'tesseract_command' => 'tesseract "%s" stdout',
        ],
        'hybrid' => [
            'enabled' => true,
            'try_pdfparser_first' => true,
            'ocr_fallback_threshold' => 100, // characters
        ],
    ],

    /**
     * Quality Settings
     */
    'quality' => [
        'ocr_resolution' => 150, // DPI
        'image_quality' => 'png16m',
        'text_extraction_quality' => 'layout', // layout, raw, simple
    ],

    /**
     * Error Handling
     */
    'error_handling' => [
        'fallback_to_simple_strategy' => true,
        'log_all_errors' => true,
        'continue_on_partial_failure' => true,
        'max_retry_attempts' => 2,
    ],
];

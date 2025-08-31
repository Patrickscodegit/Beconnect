<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Robaws Integration Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration settings for Robaws integration,
    | including feature flags and pipeline selection.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Enhanced Integration Flag
    |--------------------------------------------------------------------------
    |
    | Determines whether to use the enhanced integration pipeline with
    | JsonFieldMapper or fall back to the legacy approach.
    |
    */
    'use_enhanced_integration' => env('ROBAWS_USE_ENHANCED', true),

    /*
    |--------------------------------------------------------------------------
    | Field Mapping Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the JsonFieldMapper service that handles sophisticated
    | field mapping between extraction data and Robaws format.
    |
    */
    'field_mapping' => [
        'config_file' => config_path('robaws-field-mapping.json'),
        'cache_mappings' => env('ROBAWS_CACHE_MAPPINGS', true),
        'validation_strict' => env('ROBAWS_VALIDATION_STRICT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Dedicated logging settings for Robaws operations to keep them separate
    | from general application logs.
    |
    */
    'logging' => [
        'channel' => env('ROBAWS_LOG_CHANNEL', 'robaws'),
        'level' => env('ROBAWS_LOG_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export Settings
    |--------------------------------------------------------------------------
    |
    | Settings for document export and offer creation in Robaws.
    |
    */
    'export' => [
        'auto_create_offers' => env('ROBAWS_AUTO_CREATE_OFFERS', true),
        'validate_before_export' => env('ROBAWS_VALIDATE_BEFORE_EXPORT', true),
        'retry_failed_exports' => env('ROBAWS_RETRY_FAILED_EXPORTS', true),
        'max_retries' => env('ROBAWS_MAX_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Validation
    |--------------------------------------------------------------------------
    |
    | Validation rules and requirements for Robaws data export.
    |
    */
    'validation' => [
        'required_fields' => ['customer', 'por', 'pod', 'cargo'],
        'recommended_fields' => ['client_email', 'customer_reference', 'dim_bef_delivery'],
        'strict_email_validation' => env('ROBAWS_STRICT_EMAIL_VALIDATION', true),
    ],
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key'    => env('OPENAI_API_KEY'),
        'base_url'   => env('OPENAI_BASE', 'https://api.openai.com/v1'),
        'model'      => env('OPENAI_MODEL', 'gpt-4o'),
        'model_cheap'=> env('OPENAI_MODEL_CHEAP', 'gpt-4o-mini'),
        'vision_model'=> env('OPENAI_VISION_MODEL', 'gpt-4o'),
        'max_output_tokens' => env('OPENAI_MAX_OUTPUT_TOKENS', 900),
        'timeout'    => env('OPENAI_TIMEOUT', 20),
        'rate_limit_per_minute' => env('RATE_LIMIT_OPENAI_REQUESTS_PER_MINUTE', 50),
        'max_tokens_input' => env('OPENAI_MAX_TOKENS_INPUT', 120000),
    ],

    'anthropic' => [
        'api_key'  => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE', 'https://api.anthropic.com/v1'),
        'model'    => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-20241022'),
        'version'  => env('ANTHROPIC_VERSION', '2023-06-01'),
        'max_output_tokens' => env('ANTHROPIC_MAX_OUTPUT_TOKENS', 900),
        'timeout'  => env('ANTHROPIC_TIMEOUT', 20),
        'rate_limit_per_minute' => env('RATE_LIMIT_ANTHROPIC_REQUESTS_PER_MINUTE', 50),
        'max_tokens_input' => env('ANTHROPIC_MAX_TOKENS_INPUT', 200000),
    ],

    'tesseract' => [
        'path' => env('TESSERACT_PATH', '/opt/homebrew/bin/tesseract'),
        'languages' => env('TESSERACT_LANGUAGES', 'eng'),
        'confidence_threshold' => env('OCR_CONFIDENCE_THRESHOLD', 60),
    ],

    'ghostscript' => [
        'path' => env('GHOSTSCRIPT_PATH', '/opt/homebrew/bin/gs'),
    ],

    'poppler' => [
        'path' => env('POPPLER_PATH', '/opt/homebrew/bin'),
    ],

        'pdf' => [
        'dpi' => env('PDF_OCR_DPI', 300),
        'max_pages' => env('PDF_MAX_PAGES', 100),
    ],

    'imagemagick' => [
        'convert' => env('IMAGEMAGICK_CONVERT_CMD', 'convert'),
    ],

    'images' => [
        'jpeg_quality' => (int) env('JPEG_QUALITY', 85),
    ],

    'ocr' => [
        'rate_limit_per_minute' => env('RATE_LIMIT_OCR_REQUESTS_PER_MINUTE', 100),
    ],

    'processing' => [
        'max_file_size_mb' => env('MAX_FILE_SIZE_MB', 50),
        'max_processing_time_seconds' => env('MAX_PROCESSING_TIME_SECONDS', 300),
        'max_retry_attempts' => env('MAX_RETRY_ATTEMPTS', 3),
    ],

    'robaws' => [
        'base_url' => env('ROBAWS_BASE_URL', 'https://app.robaws.com'),
        'auth' => env('ROBAWS_AUTH', 'basic'), // 'basic' or 'bearer'
        'username' => env('ROBAWS_USERNAME'),
        'password' => env('ROBAWS_PASSWORD'),
        'token' => env('ROBAWS_TOKEN'),
        'api_key' => env('ROBAWS_API_KEY'),
        'api_secret' => env('ROBAWS_API_SECRET'),
        'timeout' => env('ROBAWS_TIMEOUT', 30),
        'auto_create_quotations' => env('ROBAWS_AUTO_CREATE_QUOTATIONS', false),
        'default_client_id' => env('ROBAWS_DEFAULT_CLIENT_ID', 1),
        'default_company_id' => env('ROBAWS_DEFAULT_COMPANY_ID', env('ROBAWS_COMPANY_ID', 1)),
        'convert_images_to_pdf' => env('ROBAWS_CONVERT_IMAGES_TO_PDF', true),
        'upload_max_mb' => env('ROBAWS_UPLOAD_MAX_MB', 25),
        'upload_retries' => env('ROBAWS_UPLOAD_RETRIES', 2),
        'upload_backoff_ms' => env('ROBAWS_UPLOAD_BACKOFF_MS', 800),
        'labels' => [
            'customer' => 'Customer',
            'contact' => 'Contact',
            'endcustomer' => 'Endcustomer',
            'customer_reference' => 'Customer reference',
            'por' => 'POR',
            'pol' => 'POL', 
            'pot' => 'POT',
            'pod' => 'POD',
            'fdest' => 'FDEST',
            'cargo' => 'CARGO',
            'dim_bef_delivery' => 'DIM_BEF_DELIVERY',
            'json' => 'JSON',
        ],
    ],

];

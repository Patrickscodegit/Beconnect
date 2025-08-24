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
        'dpi' => env('PDF_DPI', 300),
        'max_pages' => env('PDF_MAX_PAGES', 100),
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
        'base_url' => env('ROBAWS_BASE_URL', 'https://api.robaws.com'),
        'api_key' => env('ROBAWS_API_KEY', ''),
        'sandbox' => env('ROBAWS_SANDBOX', true),
        'timeout' => env('ROBAWS_TIMEOUT', 30),
    ],

];

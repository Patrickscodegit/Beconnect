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
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4-turbo-preview'),
        'rate_limit_per_minute' => env('RATE_LIMIT_OPENAI_REQUESTS_PER_MINUTE', 50),
        'max_tokens_input' => env('OPENAI_MAX_TOKENS_INPUT', 120000),
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

];

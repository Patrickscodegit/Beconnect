<?php

return [
    /**
     * Intake processing configuration
     */
    'processing' => [
        // File types that should skip contact validation and go directly to extraction
        'skip_contact_validation' => [
            'image/jpeg',
            'image/jpg', 
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'image/bmp',
            'image/tiff',
            'application/pdf',
            'message/rfc822', // .eml files usually have contact info in headers
        ],
        
        // Maximum file size in bytes (100MB default)
        'max_file_size' => env('INTAKE_MAX_FILE_SIZE', 104857600),
        
        // Enable enhanced extraction for images
        'enable_image_extraction' => env('INTAKE_ENABLE_IMAGE_EXTRACTION', true),
        
        // Always allow processing even without contact info
        'require_contact_info' => env('INTAKE_REQUIRE_CONTACT_INFO', false),
    ],
    
    /**
     * Extraction configuration
     */
    'extraction' => [
        // Retry failed extractions
        'retry_on_failure' => true,
        'max_retries' => 3,
        'retry_delay' => 60, // seconds
        
        // AI extraction settings
        'use_ai_extraction' => env('USE_AI_EXTRACTION', true),
        'ai_model' => env('AI_MODEL', 'gpt-4o'),
        
        // Image extraction specific settings
        'image_extraction_timeout' => 120, // seconds
        'enable_ocr' => env('ENABLE_OCR', true),
    ],
    
    /**
     * Status definitions
     */
    'statuses' => [
        'pending' => 'Pending Processing',
        'processing' => 'Processing',
        'export_queued' => 'Queued for Export',
        'export_failed' => 'Export Failed',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'needs_contact' => 'Contact Information Required', // Rarely used for images/PDFs
        'needs_review' => 'Review Required',
    ],
    
    /**
     * File type mappings
     */
    'file_types' => [
        'images' => [
            'mime_types' => [
                'image/jpeg',
                'image/jpg',
                'image/png',
                'image/gif',
                'image/webp',
                'image/svg+xml',
                'image/bmp',
                'image/tiff'
            ],
            'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff'],
            'auto_extract' => true,
            'require_contact' => false,
        ],
        'documents' => [
            'mime_types' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ],
            'extensions' => ['pdf', 'doc', 'docx'],
            'auto_extract' => true,
            'require_contact' => false,
        ],
        'emails' => [
            'mime_types' => ['message/rfc822'],
            'extensions' => ['eml'],
            'auto_extract' => true,
            'require_contact' => false, // Email headers usually contain contact info
        ],
        'text' => [
            'mime_types' => ['text/plain', 'text/csv'],
            'extensions' => ['txt', 'csv'],
            'auto_extract' => true,
            'require_contact' => true, // Text files might need manual contact info
        ]
    ]
];

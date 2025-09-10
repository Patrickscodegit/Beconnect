<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Vehicle Data Enhancement
    |--------------------------------------------------------------------------
    |
    | Configure the hybrid vehicle data enhancement system that combines
    | database lookups and AI-generated specifications.
    |
    */
    'enable_vehicle_enhancement' => env('EXTRACTION_ENABLE_VEHICLE_ENHANCEMENT', true),
    
    'enhancement' => [
        'database_confidence_threshold' => 0.8,
        'ai_enhancement_enabled' => true,
        'cache_ttl' => 86400, // 24 hours
        
        'critical_fields' => [
            'dimensions',
            'weight', 
            'cargo_volume'
        ]
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Export Configuration
    |--------------------------------------------------------------------------
    |
    | Configure export behavior including customer normalization
    |
    */
    'export' => [
        'use_normalizer' => env('EXPORT_USE_NORMALIZER', false),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Document Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configure where documents are stored. Supports 'local' and 'spaces' 
    | (DigitalOcean Spaces) disks as defined in config/filesystems.php
    |
    */
    'document_storage' => [
        'disk' => env('DOCUMENT_STORAGE_DISK', 'local'), // 'local' or 'spaces'
        'path_prefix' => 'documents',
    ],
];

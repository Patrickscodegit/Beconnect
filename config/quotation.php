<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Quotation System Feature Flags
    |--------------------------------------------------------------------------
    |
    | Control whether the quotation system is enabled and its features.
    |
    */

    'enabled' => env('QUOTATION_SYSTEM_ENABLED', true),

    'auto_create_from_intake' => env('QUOTATION_AUTO_CREATE_FROM_INTAKE', true),

    'show_in_schedules' => env('QUOTATION_SHOW_IN_SCHEDULES', true),

    /*
    |--------------------------------------------------------------------------
    | Profit Margins by Customer Role
    |--------------------------------------------------------------------------
    |
    | Profit margin percentages applied to base article prices based on
    | customer role from the Robaws Role field.
    |
    */

    'profit_margins' => [
        'default' => env('QUOTATION_DEFAULT_MARGIN', 15), // 15% default margin

        'by_role' => [
            'RORO' => 10,
            'POV' => 12,
            'CONSIGNEE' => 15,
            'FORWARDER' => 8,
            'HOLLANDICO' => 20,
            'INTERMEDIATE' => 12,
            'EMBASSY' => 15,
            'TRANSPORT_COMPANY' => 10,
            'SHIPPING_LINE' => 8,
            'OEM' => 12,
            'BROKER' => 10,
            'RENTAL' => 15,
            'LUXURY_CAR_DEALER' => 18,
            'CAR_DEALER' => 12,
            'BLACKLISTED' => 25, // Higher margin for risky customers
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer Roles
    |--------------------------------------------------------------------------
    |
    | Customer roles for quotation requests. These roles are used for
    | display in forms and for calculating profit margins.
    |
    */

    'customer_roles' => [
        'RORO' => 'RORO Customer',
        'POV' => 'POV Customer',
        'CONSIGNEE' => 'Consignee',
        'FORWARDER' => 'Freight Forwarder',
        'HOLLANDICO' => 'Hollandico / Belgaco',
        'INTERMEDIATE' => 'Intermediate',
        'EMBASSY' => 'Embassy',
        'TRANSPORT_COMPANY' => 'Transport Company',
        'SHIPPING_LINE' => 'Shipping Line',
        'OEM' => 'OEM / Manufacturer',
        'BROKER' => 'Broker',
        'RENTAL' => 'Rental Company',
        'LUXURY_CAR_DEALER' => 'Luxury Car Dealer',
        'CAR_DEALER' => 'Car Dealer',
        'BLACKLISTED' => 'Blacklisted Customer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer Types
    |--------------------------------------------------------------------------
    |
    | Different pricing structures and article sets for different customer
    | types based on tariff document structure.
    |
    */

    'customer_types' => [
        'FORWARDERS' => 'Freight Forwarders',
        'GENERAL' => 'General Customers / End Clients',
        'CIB' => 'Car Investment Bree',
        'PRIVATE' => 'Private Persons & Commercial Imports',
        'HOLLANDICO' => 'Hollandico / Belgaco Intervention',
        'OLDTIMER' => 'Oldtimer via Hollandico',
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Types
    |--------------------------------------------------------------------------
    |
    | All logistics service types offered based on tariff document.
    |
    */

    'service_types' => [
        // RORO Services
        'RORO_EXPORT' => [
            'name' => 'RORO Export',
            'direction' => 'EXPORT',
            'unit' => 'per car',
            'requires_schedule' => true,
        ],
        'RORO_IMPORT' => [
            'name' => 'RORO Import',
            'direction' => 'IMPORT',
            'unit' => 'per car',
            'requires_schedule' => true,
        ],

        // FCL Services
        'FCL_EXPORT' => [
            'name' => 'FCL Export',
            'direction' => 'EXPORT',
            'unit' => 'per container',
            'quantity_tiers' => [1, 2, 3, 4], // Vehicles per container
            'requires_schedule' => false,
        ],
        'FCL_IMPORT' => [
            'name' => 'FCL Import',
            'direction' => 'IMPORT',
            'unit' => 'per container',
            'quantity_tiers' => [1, 2, 3, 4], // Vehicles per container
            'requires_schedule' => false,
        ],
        'FCL_EXPORT_CONSOL' => [
            'name' => 'FCL Export Vehicle Consol',
            'direction' => 'EXPORT',
            'unit' => 'per car',
            'quantity_tiers' => [2, 3], // 2-pack or 3-pack only
            'requires_schedule' => true,
            'has_formula_pricing' => true, // Ocean freight formula
        ],
        'FCL_IMPORT_CONSOL' => [
            'name' => 'FCL Import Vehicle Consol',
            'direction' => 'IMPORT',
            'unit' => 'per car',
            'quantity_tiers' => [2, 3], // 2-pack or 3-pack only
            'requires_schedule' => true,
            'has_formula_pricing' => true, // Ocean freight formula
        ],

        // LCL Services
        'LCL_EXPORT' => [
            'name' => 'LCL Export',
            'direction' => 'EXPORT',
            'unit' => 'per handling',
            'requires_schedule' => false,
        ],
        'LCL_IMPORT' => [
            'name' => 'LCL Import',
            'direction' => 'IMPORT',
            'unit' => 'per handling',
            'requires_schedule' => false,
        ],

        // Break Bulk
        'BB_EXPORT' => [
            'name' => 'BB Export',
            'direction' => 'EXPORT',
            'unit' => 'per slot',
            'requires_schedule' => true,
        ],
        'BB_IMPORT' => [
            'name' => 'BB Import',
            'direction' => 'IMPORT',
            'unit' => 'per slot',
            'requires_schedule' => true,
        ],

        // Air Freight
        'AIRFREIGHT_EXPORT' => [
            'name' => 'Airfreight Export',
            'direction' => 'EXPORT',
            'unit' => 'per kg',
            'requires_schedule' => false,
        ],
        'AIRFREIGHT_IMPORT' => [
            'name' => 'Airfreight Import',
            'direction' => 'IMPORT',
            'unit' => 'per kg',
            'requires_schedule' => false,
        ],

        // Cross Trade
        'CROSSTRADE' => [
            'name' => 'Crosstrade',
            'direction' => 'CROSS_TRADE',
            'unit' => 'per shipment',
            'requires_schedule' => true,
        ],

        // Additional Services
        'ROAD_TRANSPORT' => [
            'name' => 'Road Transport',
            'direction' => 'BOTH',
            'unit' => 'per transport',
            'requires_schedule' => false,
        ],
        'CUSTOMS' => [
            'name' => 'Customs',
            'direction' => 'BOTH',
            'unit' => 'per clearance',
            'requires_schedule' => false,
        ],
        'PORT_FORWARDING' => [
            'name' => 'Port Forwarding',
            'direction' => 'BOTH',
            'unit' => 'per service',
            'requires_schedule' => false,
        ],
        'OTHER' => [
            'name' => 'Other',
            'direction' => 'BOTH',
            'unit' => 'per service',
            'requires_schedule' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | VAT Configuration
    |--------------------------------------------------------------------------
    |
    | Belgian VAT rate and calculation settings.
    |
    */

    'vat_rate' => env('QUOTATION_VAT_RATE', 21.00), // Belgium VAT rate (21%)

    'vat_applicable' => true,

    /*
    |--------------------------------------------------------------------------
    | Article Categories
    |--------------------------------------------------------------------------
    |
    | Article classification for organization and filtering.
    |
    */

    'article_categories' => [
        'seafreight' => 'Seafreight / Ocean Freight',
        'precarriage' => 'Pre-carriage / Trucking to Port',
        'oncarriage' => 'On-carriage / Trucking from Port',
        'customs' => 'Customs Clearance & Documentation',
        'warehouse' => 'Warehouse Services',
        'administration' => 'Administration & Documentation Fees',
        'courier' => 'Courier Services',
        'insurance' => 'Cargo Insurance',
        'miscellaneous' => 'Miscellaneous Surcharges',
        'general' => 'General Services',
    ],

    /*
    |--------------------------------------------------------------------------
    | Article Extraction from Offers
    |--------------------------------------------------------------------------
    |
    | Settings for extracting article data from Robaws offers endpoint.
    |
    */

    'article_extraction' => [
        'source' => 'offers', // Extract from /api/v2/offers (accessible)
        'max_offers_to_process' => env('ROBAWS_ARTICLE_EXTRACTION_LIMIT', 500),
        'batch_size' => 50, // Process 50 offers per batch
        'request_delay_ms' => 500, // 500ms delay between API requests
        'enable_carrier_parsing' => true, // Parse carrier names from descriptions
        'enable_smart_categorization' => true, // Auto-categorize by keywords
        'enable_parent_child_detection' => true, // Detect article bundles
        'mark_uncategorized_for_review' => true, // Flag for manual review
    ],

    /*
    |--------------------------------------------------------------------------
    | File Upload Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for quotation request file uploads.
    |
    */

    'file_uploads' => [
        'max_file_size' => env('QUOTATION_MAX_FILE_SIZE', 10240), // 10MB in KB
        'allowed_types' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xlsx', 'xls', 'zip'],
        'storage_disk' => env('QUOTATION_STORAGE_DISK', 'documents'), // Uses environment-aware disk
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Notification Settings
    |--------------------------------------------------------------------------
    |
    | Email safety and notification configuration.
    |
    */

    'notifications' => [
        'email_safety' => [
            'mode' => env('QUOTATION_EMAIL_MODE', 'safe'), // safe, disabled, live
            'test_recipient' => env('QUOTATION_TEST_EMAIL', 'patrick@belgaco.com'),
            'whitelist' => explode(',', env('QUOTATION_EMAIL_WHITELIST', 'test@belgaco.com,patrick@belgaco.com,sales@truck-time.com')),
        ],

        'team_email' => env('QUOTATION_TEAM_EMAIL', 'quotes@belgaco.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Robaws Sync Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for syncing with Robaws API.
    |
    */

    'sync' => [
        'method' => env('ROBAWS_SYNC_METHOD', 'polling'), // polling or webhooks
        'polling_interval' => 3600, // 1 hour (in seconds)
        'webhooks_enabled' => env('ROBAWS_WEBHOOKS_ENABLED', false), // Enable when Robaws approves
    ],

    /*
    |--------------------------------------------------------------------------
    | Development & Testing
    |--------------------------------------------------------------------------
    |
    | Settings for development and testing environment.
    |
    */

    'dev_mode' => env('QUOTATION_DEV_MODE', false),

    'show_debug' => env('QUOTATION_SHOW_DEBUG', false),

    'bypass_auth' => env('QUOTATION_BYPASS_AUTH', false), // For testing without authentication

    'dev_user_email' => env('QUOTATION_DEV_USER_EMAIL', 'test@belgaco.com'),

    'allow_test_routes' => env('QUOTATION_ALLOW_TEST_ROUTES', true),

    /*
    |--------------------------------------------------------------------------
    | Template Variables
    |--------------------------------------------------------------------------
    |
    | Available variables for offer templates.
    |
    */

    'template_variables' => [
        'contactPersonName' => 'Contact person name',
        'POL' => 'Port of Loading',
        'POD' => 'Port of Discharge',
        'POR' => 'Place of Receipt',
        'FDEST' => 'Final Destination',
        'CARGO' => 'Cargo description',
        'DIM_BEF_DELIVERY' => 'Cargo dimensions and details',
        'TRANSHIPMENT' => 'Transhipment details',
        'FREQUENCY' => 'Sailing frequency',
        'TRANSIT_TIME' => 'Transit time in days',
        'NEXT_SAILING' => 'Next sailing date',
    ],

    /*
    |--------------------------------------------------------------------------
    | Known Carriers
    |--------------------------------------------------------------------------
    |
    | List of known shipping carriers for parsing from article descriptions.
    |
    */

    'known_carriers' => [
        'MSC',
        'CMA',
        'CMA CGM',
        'HAPAG',
        'HAPAG-LLOYD',
        'EVERGREEN',
        'MAERSK',
        'COSCO',
        'ONE',
        'YANG MING',
        'GRIMALDI',
        'SAFWAT',
        'NEW VISION',
        'ZDRAVZAR',
    ],

];

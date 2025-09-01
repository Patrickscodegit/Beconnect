<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'documents' => (function () {
            $driver = env('DOCUMENTS_DRIVER', env('FILESYSTEM_DISK', 'local'));

            if ($driver === 's3') {
                // DigitalOcean Spaces (S3 compatible)
                return [
                    'driver' => 's3',
                    'key' => env('SPACES_KEY'),
                    'secret' => env('SPACES_SECRET'),
                    'region' => env('SPACES_REGION', 'ams3'),
                    'bucket' => env('SPACES_BUCKET'),
                    'endpoint' => env('SPACES_ENDPOINT', 'https://ams3.digitaloceanspaces.com'),
                    'use_path_style_endpoint' => false,
                    'visibility' => 'private',
                    // Prefix inside the bucket for your app's docs
                    'root' => env('DOCUMENTS_ROOT', 'documents'),
                    'throw' => true,
                ];
            }

            // Local/dev
            return [
                'driver' => 'local',
                'root' => storage_path('app/documents'),
                'visibility' => 'private',
                'throw' => true,
            ];
        })(),

        'minio' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'spaces' => [
            'driver' => 's3',
            'key' => env('SPACES_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('SPACES_SECRET', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('SPACES_REGION', env('AWS_DEFAULT_REGION', 'ams3')),
            'bucket' => env('SPACES_BUCKET', env('AWS_BUCKET')),
            'url' => env('SPACES_URL'),
            'endpoint' => env('SPACES_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('SPACES_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for document storage across environments
    |
    */

    'documents_config' => [
        'default_disk' => env('DOCUMENTS_DRIVER', env('FILESYSTEM_DISK', 'local')),
        'local_fallback' => 'local',
        'production_disk' => 'spaces',
    ],

];

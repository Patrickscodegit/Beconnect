<?php

use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

return [

    /*
    |--------------------------------------------------------------------------
    | Class Namespace
    |--------------------------------------------------------------------------
    |
    | This value sets the root class namespace for Livewire component classes in
    | your application. This value affects component auto-discovery and
    | any Livewire file helper commands, like `artisan make:livewire`.
    |
    | After changing this item, run: `php artisan livewire:discover`.
    |
    */

    'class_namespace' => 'App\\Livewire',

    /*
    |--------------------------------------------------------------------------
    | View Path
    |--------------------------------------------------------------------------
    |
    | This value sets the path for Livewire component views. This affects
    | file manipulation helper commands like `artisan make:livewire`.
    |
    */

    'view_path' => resource_path('views/livewire'),

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    | The default layout view that will be used when rendering a component via
    | Route::get('/some-endpoint', SomeComponent::class);. In this case the
    | the view returned by SomeComponent will be wrapped in "layouts.app"
    |
    */

    'layout' => 'layouts.app',

    /*
    |--------------------------------------------------------------------------
    | Lazy Loading Placeholder
    |--------------------------------------------------------------------------
    | Livewire allows you to lazy load components that would otherwise slow down
    | the initial page load. Every component can have a custom placeholder or
    | you can define the default placeholder view for all components below.
    |
    */

    'lazy_placeholder' => null,

    /*
    |--------------------------------------------------------------------------
    | Temporary File Uploads
    |--------------------------------------------------------------------------
    |
    | Livewire handles file uploads by storing uploads in a temporary directory
    | before the file is validated and stored permanently. All file uploads
    | are directed to a global endpoint for temporary storage. The config
    | items below are used for customizing the way the temporary uploads work.
    |
    */

    'temporary_file_uploads' => [

        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK', 'spaces'),

        'rules' => ['required', 'file', 'max:51200'], // 50MB Max

        'path' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_PATH', 'livewire-tmp'),

        'directory_separator' => '/',

        'middleware' => null,

        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],

        'max_upload_time' => 5, // minutes

        'cleanup' => true,

        's3' => [
            /*
             * Supported values for `visibility` are `public` and `private`.
             *
             * Supported values for `expires` are any value that can be passed to DateTime constructor
             * https://www.php.net/manual/en/datetime.formats.php or `null` to disable expiration.
             */
            'default' => [
                'visibility' => 'private',
                'expires' => '+30 minutes',
            ],

            /*
             * Supported values for each `mimes.*` rule is a mime type like 'image/png' or
             * a file extension like 'png'. You can also use a `*` wildcard to match any mime
             * type or file extension. If you want to support any file type, you can use 'star/star'.
             */
            'mimes' => [
                'image/*' => [
                    'visibility' => 'private',
                    'expires' => '+30 minutes',
                ],
                'video/*' => [
                    'visibility' => 'private',
                    'expires' => '+1 hour',
                ],
                '*/*' => [
                    'visibility' => 'private',
                    'expires' => '+30 minutes',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Render On Redirect
    |--------------------------------------------------------------------------
    |
    | This value determines if Livewire will run a component's `render()` method
    | after a redirect has been triggered using something like `redirect(...)`
    | If this is disabled, the `render()` method will not be run prior to the redirect.
    |
    */

    'render_on_redirect' => false,

    /*
    |--------------------------------------------------------------------------
    | Eloquent Model Binding
    |--------------------------------------------------------------------------
    |
    | Previous versions of Livewire supported binding directly to eloquent model
    | properties using wire:model by default. However, this approach has many
    | gotchas that are very challenging for developers to avoid. For this reason,
    | you must explicitly opt-in to this behavior.
    |
    */

    'legacy_model_binding' => false,

    /*
    |--------------------------------------------------------------------------
    | Auto-inject Frontend Assets
    |--------------------------------------------------------------------------
    |
    | By default, Livewire automatically injects its JavaScript and CSS into the
    | <head> and <body> of pages containing Livewire components. By disabling
    | this behavior, you need to use @livewireStyles and @livewireScripts.
    |
    */

    'inject_assets' => true,

    /*
    |--------------------------------------------------------------------------
    | Navigate (SPA mode)
    |--------------------------------------------------------------------------
    |
    | By default, page navigation in a Livewire app should feel like a SPA
    | (Single Page Application). The following configuration items allow you
    | to customize the way Livewire handles page navigation.
    |
    */

    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => '#2299dd',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTML Morph Markers
    |--------------------------------------------------------------------------
    |
    | Livewire intelligently "morphs" existing HTML into the newly rendered HTML
    | after each update. To make this process more reliable, Livewire injects
    | "markers" into the rendered Blade surrounding @if, @class & @style
    | directives. This configuration item allows you to disable this behavior.
    |
    */

    'inject_morph_markers' => true,

    /*
    |--------------------------------------------------------------------------
    | Pagination Theme
    |--------------------------------------------------------------------------
    |
    | When Livewire's paginate() method is used, a custom pagination view
    | is used to render the pagination links. This configuration item
    | allows you to change the view that is used to render the links.
    |
    */

    'pagination_theme' => 'tailwind',

];

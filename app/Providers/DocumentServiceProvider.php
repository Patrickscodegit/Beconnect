<?php

namespace App\Providers;

use App\Services\DocumentService;
use App\Services\LlmExtractor;
use App\Services\OcrService;
use App\Services\PdfService;
use Illuminate\Support\ServiceProvider;

class DocumentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(LlmExtractor::class);
        $this->app->singleton(OcrService::class);
        $this->app->singleton(PdfService::class);
        
        $this->app->singleton(DocumentService::class, function ($app) {
            return new DocumentService(
                $app->make(OcrService::class),
                $app->make(PdfService::class),
                $app->make(LlmExtractor::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}

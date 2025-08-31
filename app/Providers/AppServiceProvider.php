<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register extraction services
        $this->app->singleton(\App\Services\Extraction\ExtractionPipeline::class);
        $this->app->singleton(\App\Services\Extraction\IntegrationDispatcher::class);
        $this->app->singleton(\App\Services\Extraction\Strategies\ExtractionStrategyFactory::class);
        
        // Register extraction strategies
        $this->app->bind(\App\Services\Extraction\Strategies\EmailExtractionStrategy::class);
        
        // Register Robaws services with proper DI
        $this->registerRobawsServices();
    }

    /**
     * Register Robaws-related services with dependency injection
     */
    private function registerRobawsServices(): void
    {
        // Bind the Robaws client
        $this->app->singleton(\App\Services\RobawsClient::class);

        // Bind the payload builder
        $this->app->singleton(\App\Services\Robaws\RobawsPayloadBuilder::class);

        // Bind the interface to the NEW exporter
        $this->app->singleton(
            \App\Services\Robaws\Contracts\RobawsExporter::class,
            \App\Services\Robaws\RobawsExportService::class
        );

        // Backward compatibility: redirect OLD concrete class to NEW
        $this->app->alias(
            \App\Services\Robaws\RobawsExportService::class,
            \App\Services\RobawsExportService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Document::observe(\App\Observers\DocumentObserver::class);
        \App\Models\Extraction::observe(\App\Observers\ExtractionObserver::class);
    }
}

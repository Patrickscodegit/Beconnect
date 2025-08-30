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

<?php

namespace App\Providers;

use App\Services\Extraction\Strategies\ExtractionStrategyFactoryInterface;
use App\Services\Extraction\Strategies\IsolatedExtractionStrategyFactory;
use App\Services\Extraction\Strategies\ExtractionStrategyFactory;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class IsolatedExtractionServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the interface binding
        $this->app->singleton(ExtractionStrategyFactoryInterface::class, function ($app) {
            $useIsolated = config('extraction.use_isolated_strategies', true);
            
            if ($useIsolated) {
                return new IsolatedExtractionStrategyFactory();
            } else {
                return new ExtractionStrategyFactory();
            }
        });

        // Keep the concrete class bindings for backward compatibility
        $this->app->singleton(ExtractionStrategyFactory::class, function ($app) {
            return $app->make(ExtractionStrategyFactoryInterface::class);
        });

        // Also register the isolated factory directly
        $this->app->singleton(IsolatedExtractionStrategyFactory::class, function ($app) {
            return new IsolatedExtractionStrategyFactory();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Configuration is loaded once during application bootstrap
        // No need to log on every request
    }
}

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
                Log::info('Registering ISOLATED extraction strategy factory', [
                    'provider' => 'IsolatedExtractionServiceProvider',
                    'isolation_level' => 'complete'
                ]);
                
                return new IsolatedExtractionStrategyFactory();
            } else {
                Log::info('Registering SHARED extraction strategy factory', [
                    'provider' => 'IsolatedExtractionServiceProvider',
                    'isolation_level' => 'none'
                ]);
                
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
        // Log the strategy configuration on boot
        $strategyMode = config('extraction.strategy_mode', 'isolated');
        $useIsolated = config('extraction.use_isolated_strategies', true);
        
        Log::info('Extraction strategy configuration loaded', [
            'strategy_mode' => $strategyMode,
            'use_isolated_strategies' => $useIsolated,
            'provider' => 'IsolatedExtractionServiceProvider'
        ]);
    }
}

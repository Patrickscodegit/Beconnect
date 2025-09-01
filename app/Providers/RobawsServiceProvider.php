<?php

namespace App\Providers;

use App\Services\Export\Mappers\RobawsMapper;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Support\ServiceProvider;

class RobawsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the API client as singleton
        $this->app->singleton(RobawsApiClient::class, function ($app) {
            return new RobawsApiClient();
        });

        // Register the mapper as singleton
        $this->app->singleton(RobawsMapper::class, function ($app) {
            return new RobawsMapper();
        });

        // Register the main export service using the correct namespace
        $this->app->singleton(\App\Services\Robaws\RobawsExportService::class, function ($app) {
            return new \App\Services\Robaws\RobawsExportService(
                $app->make(RobawsMapper::class),
                $app->make(RobawsApiClient::class)
            );
        });

        // Bind the legacy service to use the new implementation
        $this->app->bind(\App\Services\RobawsExportService::class, function ($app) {
            return $app->make(\App\Services\Robaws\RobawsExportService::class);
        });
    }

    /**
     * Bootstrap services.
     */
    public function bootstrap(): void
    {
        //
    }
}

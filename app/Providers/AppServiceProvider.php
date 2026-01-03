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
        $this->app->singleton(\App\Services\Extraction\Strategies\ExtractionStrategyFactoryInterface::class, function ($app) {
            return new \App\Services\Extraction\Strategies\ExtractionStrategyFactory();
        });
        
        // Register extraction strategies
        $this->app->bind(\App\Services\Extraction\Strategies\EmailExtractionStrategy::class);
        
        // Register Phase 1 optimization services
        $this->registerPhase1OptimizationServices();
        
        // Register Robaws services with proper DI
        $this->registerRobawsServices();
        
        // Register pricing services (VAT resolution)
        $this->registerPricingServices();
    }

    /**
     * Register Phase 1 optimization services
     */
    private function registerPhase1OptimizationServices(): void
    {
        // Register Phase 1 optimization classes as singletons
        $this->app->singleton(\App\Services\Extraction\Strategies\CompiledPatternEngine::class);
        $this->app->singleton(\App\Services\Extraction\Strategies\TempFileManager::class);
        $this->app->singleton(\App\Services\Extraction\Strategies\MemoryMonitor::class);
        $this->app->singleton(\App\Services\Extraction\Strategies\PdfAnalyzer::class);
        
        // Register OptimizedPdfExtractionStrategy
        $this->app->bind(\App\Services\Extraction\Strategies\OptimizedPdfExtractionStrategy::class);
    }

    /**
     * Register pricing services (VAT resolution)
     */
    private function registerPricingServices(): void
    {
        $this->app->singleton(\App\Services\Pricing\EuCountryChecker::class);
        $this->app->singleton(\App\Services\Pricing\VatResolverInterface::class, \App\Services\Pricing\VatResolver::class);
        $this->app->singleton(\App\Services\Pricing\QuotationVatService::class);
    }

    /**
     * Register Robaws-related services with dependency injection
     */
    private function registerRobawsServices(): void
    {
        // Bind the Robaws client
        $this->app->singleton(\App\Services\RobawsClient::class);

        // Bind the JsonFieldMapper
        $this->app->bind(\App\Services\RobawsIntegration\JsonFieldMapper::class, function ($app) {
            return new \App\Services\RobawsIntegration\JsonFieldMapper();
        });

        // Bind the enhanced integration service
        $this->app->singleton(\App\Services\RobawsIntegration\EnhancedRobawsIntegrationService::class);

        // Note: RobawsIntegrationService has been removed and consolidated into EnhancedRobawsIntegrationService

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
        
        // Quotation System Observers
        \App\Models\Intake::observe(\App\Observers\IntakeObserver::class);
        \App\Models\QuotationRequest::observe(\App\Observers\QuotationRequestObserver::class);
        \App\Models\QuotationRequestArticle::observe(\App\Observers\QuotationRequestArticleObserver::class);
        
        // Port System Observers
        \App\Models\Port::observe(\App\Observers\PortObserver::class);
        
        // Configure Filament to display timestamps in Belgium timezone
        $this->configureFilamentTimezone();
    }
    
    /**
     * Configure Filament components to use Belgium timezone for display
     */
    private function configureFilamentTimezone(): void
    {
        // Only configure if Filament is installed
        if (class_exists(\Filament\Forms\Components\DateTimePicker::class)) {
            \Filament\Forms\Components\DateTimePicker::configureUsing(
                fn (\Filament\Forms\Components\DateTimePicker $component) => 
                    $component->timezone('Europe/Brussels')
            );
        }
        
        if (class_exists(\Filament\Tables\Columns\TextColumn::class)) {
            \Filament\Tables\Columns\TextColumn::configureUsing(
                fn (\Filament\Tables\Columns\TextColumn $column) => 
                    $column->timezone('Europe/Brussels')
            );
        }
        
        if (class_exists(\Filament\Infolists\Components\TextEntry::class)) {
            \Filament\Infolists\Components\TextEntry::configureUsing(
                fn (\Filament\Infolists\Components\TextEntry $entry) => 
                    $entry->timezone('Europe/Brussels')
            );
        }
    }
}

<?php

namespace App\Filament\Resources\RobawsArticleResource\Pages;

use App\Filament\Resources\RobawsArticleResource;
use App\Services\Quotation\RobawsArticlesSyncService;
use App\Jobs\SyncSingleArticleMetadataJob;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class ListRobawsArticles extends ListRecords
{
    protected static string $resource = RobawsArticleResource::class;

    protected function getHeaderActions(): array
    {
        // Get API usage for cost indicators
        $apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
        $dailyRemaining = $apiClient->getDailyRemaining();
        $articleCount = \App\Models\RobawsArticleCache::count();
        
        return [
            Actions\Action::make('syncIncremental')
                ->label('Quick Sync')
                ->icon('heroicon-o-bolt')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('⚡ Quick Sync - Changed Articles Only')
                ->modalDescription(function () use ($dailyRemaining) {
                    $estimatedCost = '~10-50 API calls';
                    return "**Estimated API Cost:** {$estimatedCost}\n**API Quota Remaining:** " . number_format($dailyRemaining) . "\n**Duration:** 1-2 minutes\n\n**What this does:**\nSyncs only articles modified since last sync - fast and efficient!\n\n**Use for:** Daily updates, regular maintenance\n**✨ Recommended for routine use**";
                })
                ->modalSubmitActionLabel('Yes, quick sync')
                ->action(function () {
                    try {
                        $syncService = app(RobawsArticlesSyncService::class);
                        $result = $syncService->syncIncremental();
                        
                        Notification::make()
                            ->title('Incremental sync completed!')
                            ->body("Synced {$result['synced']} changed articles out of {$result['total']} modified. Errors: {$result['errors']}")
                            ->success()
                            ->duration(10000) // 10 seconds
                            ->send();
                            
                        $this->redirect(static::getUrl());
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Incremental sync failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
                
            Actions\Action::make('syncAll')
                ->label('Full Sync')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('🔄 Complete System Sync')
                ->modalDescription(function () use ($dailyRemaining, $articleCount) {
                    $articlesApiCost = ceil($articleCount / 10) + 50;
                    $extraFieldsCost = $articleCount;
                    $totalCost = $articlesApiCost + $extraFieldsCost;
                    $safeToProcess = $dailyRemaining > ($totalCost + 500);
                    $status = $safeToProcess ? '✅ Safe to proceed' : '⚠️ Low quota - proceed with caution';
                    $estimatedTime = ceil($articleCount * 0.1 / 60) + 5; // Extra fields (0.1s) + article fetch (~5 min)
                    
                    return "**Estimated API Cost:** ~{$totalCost} API calls\n**API Quota Remaining:** " . number_format($dailyRemaining) . "\n**Status:** {$status}\n**Duration:** ~{$estimatedTime} minutes\n\n**What this does:**\n✅ Syncs ALL {$articleCount} articles\n✅ Extracts metadata (POL/POD, service types)\n✅ Fetches extra fields (parent items, shipping lines)\n✅ Complete Smart Article Selection data\n\n**Use for:** Initial setup, major updates, troubleshooting\n**💡 For daily updates, use Quick Sync instead**";
                })
                ->modalSubmitActionLabel('Yes, full sync')
                ->action(function () {
                    try {
                        $syncService = app(RobawsArticlesSyncService::class);
                        
                        // 1. Sync all articles
                        $result = $syncService->sync();
                        
                        // 2. Sync metadata from names
                        $metadataResult = $syncService->syncAllMetadata();
                        
                        // 3. Automatically queue extra fields sync
                        \App\Jobs\DispatchArticleExtraFieldsSyncJobs::dispatch(
                            batchSize: 50,
                            delaySeconds: 0.5
                        );
                        
                        $articleCount = \App\Models\RobawsArticleCache::count();
                        $estimatedMinutes = ceil(($articleCount * 0.5) / 60);
                        
                        Notification::make()
                            ->title('Full sync started!')
                            ->body("Synced {$result['synced']} articles. Metadata: {$metadataResult['success']}/{$metadataResult['total']}. Extra fields queued (~{$estimatedMinutes} min). Check Sync Progress page.")
                            ->success()
                            ->duration(12000)
                            ->send();
                            
                        $this->redirect(static::getUrl());
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Sync failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
                
            Actions\Action::make('syncExtraFields')
                ->label('Sync Extra Fields')
                ->icon('heroicon-o-tag')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Sync Extra Fields from Robaws API?')
                ->modalDescription(function () use ($dailyRemaining, $articleCount) {
                    $estimatedCost = $articleCount; // One API call per article
                    $estimatedTime = ceil($articleCount * 0.5 / 60); // ~0.5 seconds per article (2 req/sec)
                    $safeToProcess = $dailyRemaining > ($estimatedCost + 500);
                    $status = $safeToProcess ? '✅ Safe to proceed' : '⚠️ Low quota - proceed with caution';
                    
                    return "**Estimated API Cost:** ~{$estimatedCost} API calls (1 per article)\n**API Quota Remaining:** " . number_format($dailyRemaining) . "\n**Status:** {$status}\n**Duration:** ~{$estimatedTime} minutes\n**Rate:** 2 requests/second (safe for server stability)\n\n**What this does:**\nFetches extra fields from Robaws API for ALL {$articleCount} articles:\n• Parent Item status (checkbox)\n• Shipping Line\n• Service Type\n• POL Terminal\n• **Commodity Type (for Smart Article Selection)** 🧠\n• **POD Code (for Smart Article Selection)** 🧠\n• Update/Validity dates\n• Article Info\n\n**Use this for:** Syncing custom fields, parent items, extra metadata, and enabling Smart Article Selection\n\n**✅ Server-friendly rate limiting** - Runs in background without overloading.";
                })
                ->modalSubmitActionLabel('Yes, sync extra fields')
                ->action(function () {
                    \Log::info('SYNC_EXTRA_FIELDS_BUTTON_CLICKED');
                    
                    try {
                        \Log::info('DISPATCHING_ARTICLE_SYNC_JOBS');
                        
                        // Dispatch jobs to queue with conservative rate limiting
                        // Conservative: 2 req/sec (safe for server stability)
                        \App\Jobs\DispatchArticleExtraFieldsSyncJobs::dispatch(
                            batchSize: 50,       // Smaller batches for stability
                            delaySeconds: 0.5    // 0.5s = 2 req/sec (safe for server)
                        );
                        
                        \Log::info('ARTICLE_SYNC_JOBS_DISPATCHED_SUCCESS');
                        
                        $articleCount = \App\Models\RobawsArticleCache::count();
                        $estimatedMinutes = ceil(($articleCount * 0.5) / 60); // 0.5s per article
                        
                        \Log::info('SHOWING_SUCCESS_NOTIFICATION', [
                            'article_count' => $articleCount,
                            'estimated_minutes' => $estimatedMinutes
                        ]);
                        
                        Notification::make()
                            ->title('Extra fields sync queued!')
                            ->body("Queuing {$articleCount} sync jobs with safe rate limiting (2 req/sec). Estimated time: ~{$estimatedMinutes} minutes. Check the Sync Progress page to monitor.")
                            ->success()
                            ->duration(10000)
                            ->send();
                            
                        \Log::info('SYNC_EXTRA_FIELDS_ACTION_COMPLETED');
                            
                    } catch (\Exception $e) {
                        \Log::error('SYNC_EXTRA_FIELDS_ACTION_FAILED', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        
                        Notification::make()
                            ->title('Failed to queue extra fields sync')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
                
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Articles')
                ->badge(fn () => static::getModel()::count()),
            
            'parent' => Tab::make('Parent Articles')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_parent_item', true))
                ->badge(fn () => static::getModel()::where('is_parent_item', true)->count())
                ->badgeColor('info'),
                
            'surcharges' => Tab::make('Surcharges')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_surcharge', true))
                ->badge(fn () => static::getModel()::where('is_surcharge', true)->count())
                ->badgeColor('warning'),
                
            'review' => Tab::make('Needs Review')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('requires_manual_review', true))
                ->badge(fn () => static::getModel()::where('requires_manual_review', true)->count())
                ->badgeColor('danger'),
                
            'seafreight' => Tab::make('Seafreight')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(function($q) {
                    $q->where('category', 'seafreight')
                      ->orWhere('service_type', 'LIKE', '%SEAFREIGHT%')
                      ->orWhere('article_name', 'LIKE', '%seafreight%')
                      ->orWhere('article_name', 'LIKE', '%Seafreight%');
                }))
                ->badge(fn () => static::getModel()::where(function($q) {
                    $q->where('category', 'seafreight')
                      ->orWhere('service_type', 'LIKE', '%SEAFREIGHT%')
                      ->orWhere('article_name', 'LIKE', '%seafreight%')
                      ->orWhere('article_name', 'LIKE', '%Seafreight%');
                })->count()),
                
            'customs' => Tab::make('Customs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(function($q) {
                    $q->where('category', 'customs')
                      ->orWhere('article_name', 'LIKE', '%customs%')
                      ->orWhere('article_name', 'LIKE', '%Customs%')
                      ->orWhere('article_name', 'LIKE', '%CUSTOMS%');
                }))
                ->badge(fn () => static::getModel()::where(function($q) {
                    $q->where('category', 'customs')
                      ->orWhere('article_name', 'LIKE', '%customs%')
                      ->orWhere('article_name', 'LIKE', '%Customs%')
                      ->orWhere('article_name', 'LIKE', '%CUSTOMS%');
                })->count()),
        ];
    }
}


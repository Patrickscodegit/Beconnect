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
                ->label('Sync Changed Articles')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Sync Changed Articles from Robaws?')
                ->modalDescription(function () use ($dailyRemaining) {
                    $estimatedCost = '~10-50 API calls';
                    return "**Estimated API Cost:** {$estimatedCost}\n**API Quota Remaining:** " . number_format($dailyRemaining) . "\n\n**What this does:**\nFetches only articles modified since the last sync. This is fast, rate-limit friendly, and recommended for regular updates.\n\n**Best for:** Daily/hourly updates, webhook recovery";
                })
                ->modalSubmitActionLabel('Yes, sync changes')
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
                ->label('Full Sync (All Articles)')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('⚠️ Full Sync All Articles from Robaws?')
                ->modalDescription(function () use ($dailyRemaining, $articleCount) {
                    $estimatedCost = ceil($articleCount / 10) + 50; // Pagination estimate
                    $safeToProcess = $dailyRemaining > ($estimatedCost + 500);
                    $status = $safeToProcess ? '✅ Safe to proceed' : '⚠️ Low quota - proceed with caution';
                    
                    return "**Estimated API Cost:** ~{$estimatedCost} API calls\n**API Quota Remaining:** " . number_format($dailyRemaining) . "\n**Status:** {$status}\n**Duration:** ~3-5 minutes\n\n**What this does:**\nFetches ALL {$articleCount} articles from Robaws API and syncs metadata. This is a heavy operation.\n\n**Use this for:** Initial setup, major updates, data verification\n\n**⚠️ For regular updates, use \"Sync Changed Articles\" instead!**";
                })
                ->modalSubmitActionLabel('Yes, sync all')
                ->action(function () {
                    try {
                        $syncService = app(RobawsArticlesSyncService::class);
                        $result = $syncService->sync();
                        
                        // Automatically sync metadata
                        $metadataResult = $syncService->syncAllMetadata();
                        
                        Notification::make()
                            ->title('Full sync completed!')
                            ->body("Synced {$result['synced']} articles. Metadata sync: {$metadataResult['success']}/{$metadataResult['total']} articles.")
                            ->success()
                            ->duration(10000) // 10 seconds
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
                
            Actions\Action::make('rebuildCache')
                ->label('Rebuild Cache')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('🔴 Rebuild Entire Article Cache?')
                ->modalDescription(function () use ($dailyRemaining, $articleCount) {
                    $estimatedCost = ceil($articleCount / 10) + 50;
                    $safeToProcess = $dailyRemaining > ($estimatedCost + 500);
                    $status = $safeToProcess ? '✅ Safe to proceed' : '⚠️ Low quota - proceed with caution';
                    
                    return "**⚠️ DESTRUCTIVE OPERATION**\n\n**Estimated API Cost:** ~{$estimatedCost} API calls\n**API Quota Remaining:** " . number_format($dailyRemaining) . "\n**Status:** {$status}\n**Duration:** ~3-5 minutes\n\n**What this does:**\n1. **DELETES** all {$articleCount} cached articles\n2. Fetches everything from Robaws API\n3. Syncs metadata\n\n**Use this for:** Database corruption, schema migrations, complete system reset\n\n**⚠️ This operation cannot be undone!**\n**💡 Tip:** Try \"Full Sync\" first - it's safer!**";
                })
                ->modalSubmitActionLabel('Yes, delete and rebuild')
                ->action(function () {
                    try {
                        $syncService = app(RobawsArticlesSyncService::class);
                        $result = $syncService->rebuildCache(); // Automatically syncs metadata
                        
                        Notification::make()
                            ->title('Cache rebuilt successfully!')
                            ->body("Total: {$result['total']}, Synced: {$result['synced']}, Errors: {$result['errors']}. Metadata sync completed automatically.")
                            ->success()
                            ->duration(10000) // 10 seconds
                            ->send();
                            
                        $this->redirect(static::getUrl());
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Rebuild failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
                
            Actions\Action::make('syncAllMetadata')
                ->label('Sync All Metadata')
                ->icon('heroicon-o-sparkles')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Sync Metadata for All Articles?')
                ->modalDescription(function () use ($dailyRemaining, $articleCount) {
                    return "**Estimated API Cost:** ~0 API calls (uses name extraction)\n**API Quota Remaining:** " . number_format($dailyRemaining) . "\n**Status:** ✅ Safe, no API calls\n**Duration:** ~10-30 seconds\n\n**What this does:**\nExtracts metadata from {$articleCount} cached article names:\n• Shipping Line (ACL, Grimaldi, etc.)\n• POL/POD (ports of loading/discharge)\n• Service Type (Seafreight, RORO, etc.)\n• Trade Direction (Export/Import)\n\n**Use this for:** After parser updates, fixing missing metadata\n\n**💡 This is fast and safe - no API calls needed!**";
                })
                ->modalSubmitActionLabel('Yes, sync metadata')
                ->action(function () {
                    try {
                        $syncService = app(RobawsArticlesSyncService::class);
                        $result = $syncService->syncAllMetadata();
                        
                        Notification::make()
                            ->title('Metadata sync completed!')
                            ->body("Processed {$result['total']} articles. Success: {$result['success']}, Failed: {$result['failed']}")
                            ->success()
                            ->duration(8000)
                            ->send();
                            
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Metadata sync failed')
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


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
        return [
            Actions\Action::make('syncAll')
                ->label('Sync All Articles')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Sync All Articles from Robaws?')
                ->modalDescription('This will fetch all articles from the Robaws API and update the cache. Metadata will be synced in the background.')
                ->modalSubmitActionLabel('Yes, sync now')
                ->action(function () {
                    try {
                        $syncService = app(RobawsArticlesSyncService::class);
                        $result = $syncService->sync();
                        
                        // Automatically queue metadata sync
                        \App\Jobs\SyncArticlesMetadataBulkJob::dispatch('all');
                        
                        Notification::make()
                            ->title('Articles synced successfully!')
                            ->body("Synced {$result['synced']} articles. Metadata sync queued for background processing.")
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
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Rebuild Entire Article Cache?')
                ->modalDescription('This will clear all cached articles and fetch everything from Robaws API. Metadata will be synced in the background. This operation cannot be undone and may take several minutes.')
                ->modalSubmitActionLabel('Yes, rebuild now')
                ->action(function () {
                    try {
                        $syncService = app(RobawsArticlesSyncService::class);
                        $result = $syncService->rebuildCache(); // Automatically queues metadata sync
                        
                        Notification::make()
                            ->title('Cache rebuilt successfully!')
                            ->body("Total: {$result['total']}, Synced: {$result['synced']}, Errors: {$result['errors']}. Metadata sync queued for background processing.")
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
                ->modalDescription('This will queue metadata sync (shipping line, POL/POD, service type) for all cached articles. This happens in the background.')
                ->modalSubmitActionLabel('Yes, sync metadata')
                ->action(function () {
                    try {
                        $totalArticles = \App\Models\RobawsArticleCache::count();
                        
                        \App\Jobs\SyncArticlesMetadataBulkJob::dispatch('all');
                        
                        Notification::make()
                            ->title('Metadata sync queued!')
                            ->body("Queued metadata sync for {$totalArticles} articles. This will process in the background.")
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
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_parent_article', true))
                ->badge(fn () => static::getModel()::where('is_parent_article', true)->count())
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
                ->modifyQueryUsing(fn (Builder $query) => $query->where('category', 'seafreight'))
                ->badge(fn () => static::getModel()::where('category', 'seafreight')->count()),
                
            'customs' => Tab::make('Customs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('category', 'customs'))
                ->badge(fn () => static::getModel()::where('category', 'customs')->count()),
        ];
    }
}


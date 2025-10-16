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
                        
                        // Automatically sync metadata
                        $metadataResult = $syncService->syncAllMetadata();
                        
                        Notification::make()
                            ->title('Articles synced successfully!')
                            ->body("Synced {$result['synced']} articles. Metadata sync completed: {$metadataResult['success']}/{$metadataResult['total']} articles.")
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
                ->modalDescription('This will queue metadata sync (shipping line, POL/POD, service type) for all cached articles. This happens in the background.')
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


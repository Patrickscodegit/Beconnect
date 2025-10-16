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
                ->modalDescription('This will fetch all articles from the Robaws API and update the cache. This may take a few minutes.')
                ->modalSubmitActionLabel('Yes, sync now')
                ->action(function () {
                    try {
                        $syncService = app(RobawsArticlesSyncService::class);
                        $result = $syncService->sync();
                        
                        Notification::make()
                            ->title('Articles synced successfully!')
                            ->body("Synced {$result['synced']} articles. Errors: {$result['errors']}")
                            ->success()
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
                ->modalDescription('This will clear all cached articles and fetch everything from Robaws API. This operation cannot be undone and may take several minutes.')
                ->modalSubmitActionLabel('Yes, rebuild now')
                ->action(function () {
                    try {
                        $syncService = app(RobawsArticlesSyncService::class);
                        $result = $syncService->rebuildCache();
                        
                        Notification::make()
                            ->title('Cache rebuilt successfully!')
                            ->body("Total: {$result['total']}, Synced: {$result['synced']}, Errors: {$result['errors']}")
                            ->success()
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


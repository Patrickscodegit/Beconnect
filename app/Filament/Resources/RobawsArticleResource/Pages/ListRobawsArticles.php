<?php

namespace App\Filament\Resources\RobawsArticleResource\Pages;

use App\Filament\Resources\RobawsArticleResource;
use App\Services\Quotation\RobawsArticlesSyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListRobawsArticles extends ListRecords
{
    protected static string $resource = RobawsArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('syncArticles')
                ->label('Sync from Robaws API')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Sync Articles')
                ->modalDescription('Fetch latest articles from Robaws API and update the cache.')
                ->action(function (RobawsArticlesSyncService $syncService) {
                    try {
                        $result = $syncService->sync();
                        
                        if ($result['success']) {
                            Notification::make()
                                ->title('Articles synced successfully')
                                ->success()
                                ->body("Synced {$result['synced']} of {$result['total']} articles from Robaws API")
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Sync failed')
                                ->danger()
                                ->body($result['error'] ?? 'Unknown error')
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Sync error')
                            ->danger()
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
                
            Actions\Action::make('rebuildCache')
                ->label('Rebuild Cache')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Rebuild Article Cache')
                ->modalDescription('This will clear all cached articles and re-fetch from Robaws API. This action cannot be undone.')
                ->action(function (RobawsArticlesSyncService $syncService) {
                    try {
                        $result = $syncService->rebuildCache();
                        
                        Notification::make()
                            ->title('Cache rebuilt successfully')
                            ->success()
                            ->body("Rebuilt with {$result['synced']} articles from Robaws API")
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Rebuild failed')
                            ->danger()
                            ->body($e->getMessage())
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


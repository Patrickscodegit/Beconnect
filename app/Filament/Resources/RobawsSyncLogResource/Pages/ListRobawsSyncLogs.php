<?php

namespace App\Filament\Resources\RobawsSyncLogResource\Pages;

use App\Filament\Resources\RobawsSyncLogResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListRobawsSyncLogs extends ListRecords
{
    protected static string $resource = RobawsSyncLogResource::class;
    
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Syncs'),
            
            'success' => Tab::make('Successful')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'success'))
                ->badge(fn () => static::getModel()::where('status', 'success')->count())
                ->badgeColor('success'),
                
            'failed' => Tab::make('Failed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'failed'))
                ->badge(fn () => static::getModel()::where('status', 'failed')->count())
                ->badgeColor('danger'),
                
            'articles' => Tab::make('Articles')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('sync_type', 'articles'))
                ->badge(fn () => static::getModel()::where('sync_type', 'articles')->count()),
                
            'offers' => Tab::make('Offers')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('sync_type', 'offers'))
                ->badge(fn () => static::getModel()::where('sync_type', 'offers')->count()),
        ];
    }
}


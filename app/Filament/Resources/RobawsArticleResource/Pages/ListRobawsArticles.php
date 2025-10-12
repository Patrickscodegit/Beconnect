<?php

namespace App\Filament\Resources\RobawsArticleResource\Pages;

use App\Filament\Resources\RobawsArticleResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListRobawsArticles extends ListRecords
{
    protected static string $resource = RobawsArticleResource::class;

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


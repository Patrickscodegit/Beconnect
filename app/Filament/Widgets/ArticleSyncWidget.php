<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\RobawsArticleCache;

class ArticleSyncWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $total = RobawsArticleCache::count();
        $withMetadata = RobawsArticleCache::whereNotNull('shipping_line')->count();
        $withoutMetadata = $total - $withMetadata;
        $lastSync = RobawsArticleCache::max('last_synced_at');
        $lastMetadataSync = RobawsArticleCache::whereNotNull('shipping_line')
            ->max('updated_at');
        
        $percentage = $total > 0 ? round(($withMetadata / $total) * 100) : 0;
        
        return [
            Stat::make('Total Articles', $total)
                ->description('Cached from Robaws')
                ->descriptionIcon('heroicon-o-cube')
                ->color('primary')
                ->extraAttributes([
                    'class' => 'cursor-pointer',
                ])
                ->url(route('filament.admin.resources.robaws-articles.index')),
                
            Stat::make('With Metadata', $withMetadata)
                ->description("{$withoutMetadata} missing metadata ({$percentage}% complete)")
                ->descriptionIcon('heroicon-o-check-circle')
                ->color($withMetadata > 0 ? 'success' : 'danger'),
                
            Stat::make('Last Full Sync', $lastSync ? \Carbon\Carbon::parse($lastSync)->diffForHumans() : 'Never')
                ->description('Auto-syncs daily at 2 AM')
                ->descriptionIcon('heroicon-o-clock')
                ->color($lastSync && \Carbon\Carbon::parse($lastSync)->isToday() ? 'success' : 'warning'),
        ];
    }
    
    protected function getColumns(): int
    {
        return 3;
    }
}


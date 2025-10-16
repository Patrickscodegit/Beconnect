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
        $lastMetadataSync = RobawsArticleCache::whereNotNull('shipping_line')
            ->max('updated_at');
        
        $percentage = $total > 0 ? round(($withMetadata / $total) * 100) : 0;
        
        return [
            Stat::make('Total Articles', $total)
                ->description('Cached from Robaws')
                ->icon('heroicon-o-cube')
                ->color('primary'),
                
            Stat::make('With Metadata', $withMetadata)
                ->description("{$withoutMetadata} missing metadata ({$percentage}% complete)")
                ->icon('heroicon-o-check-circle')
                ->color($withMetadata > 0 ? 'success' : 'danger'),
                
            Stat::make('Last Metadata Sync', $lastMetadataSync ? \Carbon\Carbon::parse($lastMetadataSync)->diffForHumans() : 'Never')
                ->description('Shipping line, service type, terminal')
                ->icon('heroicon-o-clock')
                ->color($lastMetadataSync ? 'success' : 'warning'),
        ];
    }
}


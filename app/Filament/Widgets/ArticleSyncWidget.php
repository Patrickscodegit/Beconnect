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
        
        // Optimization metrics: Articles with extraFields don't need API calls
        $articlesWithExtraFields = RobawsArticleCache::where(function($query) {
            $query->whereNotNull('shipping_line')
                ->orWhereNotNull('transport_mode')
                ->orWhereNotNull('pol_terminal');
        })->count();
        
        $articlesWithoutExtraFields = max(0, $total - $articlesWithExtraFields);
        
        // Estimated API calls saved per full sync (optimization impact)
        // Before: All articles needed API calls ($total API calls)
        // After: Only articles without extraFields need API calls ($articlesWithoutExtraFields API calls)
        // Saved: $articlesWithExtraFields API calls per full sync
        $apiCallsSavedPerSync = $articlesWithExtraFields;
        $optimizationPercentage = $total > 0 ? round(($apiCallsSavedPerSync / $total) * 100) : 0;
        
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
                
            Stat::make('Optimization Impact', "~" . number_format($apiCallsSavedPerSync) . " saved/sync")
                ->description("{$optimizationPercentage}% reduction (articles with extraFields)")
                ->descriptionIcon('heroicon-o-bolt')
                ->color($apiCallsSavedPerSync > 0 ? 'success' : 'gray'),
                
            Stat::make('Articles Optimized', number_format($articlesWithExtraFields))
                ->description("Don't need API calls (have extraFields)")
                ->descriptionIcon('heroicon-o-check-circle')
                ->color($articlesWithExtraFields > 0 ? 'success' : 'gray'),
        ];
    }
    
    protected function getColumns(): int
    {
        return 5;
    }
}


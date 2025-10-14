<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\RobawsArticleCache;

class ArticleSyncWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $totalArticles = RobawsArticleCache::count();
        $lastSyncRecord = RobawsArticleCache::whereNotNull('last_synced_at')
            ->orderBy('last_synced_at', 'desc')
            ->first();
        $todaySync = RobawsArticleCache::whereDate('last_synced_at', today())->count();
        
        return [
            Stat::make('Total Articles', $totalArticles)
                ->description('From Robaws Articles API')
                ->icon('heroicon-o-cube')
                ->color('success'),
                
            Stat::make('Synced Today', $todaySync)
                ->description('Articles updated today')
                ->icon('heroicon-o-bolt')
                ->color('info'),
                
            Stat::make('Last Sync', $lastSyncRecord ? $lastSyncRecord->last_synced_at->diffForHumans() : 'Never')
                ->description('Next sync at 2:00 AM')
                ->icon('heroicon-o-clock')
                ->color('warning'),
        ];
    }
}


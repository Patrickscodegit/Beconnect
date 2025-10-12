<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use App\Models\RobawsArticleCache;
use App\Models\RobawsSyncLog;
use Illuminate\Support\Facades\DB;

class ArticleSyncWidget extends Widget
{
    protected static string $view = 'filament.widgets.article-sync-widget';
    
    protected int | string | array $columnSpan = 'full';
    
    public function getViewData(): array
    {
        $lastSync = RobawsSyncLog::where('sync_type', 'articles')
            ->orderBy('started_at', 'desc')
            ->first();
            
        return [
            'articleCount' => RobawsArticleCache::count(),
            'parentCount' => RobawsArticleCache::where('is_parent_article', true)->count(),
            'surchargeCount' => RobawsArticleCache::where('is_surcharge', true)->count(),
            'childrenCount' => DB::table('article_children')->count(),
            'lastSyncAt' => $lastSync?->started_at,
            'lastSyncStatus' => $lastSync?->status,
            'lastSyncCount' => $lastSync?->synced_count,
            'lastSyncDuration' => $lastSync?->duration_seconds,
        ];
    }
}


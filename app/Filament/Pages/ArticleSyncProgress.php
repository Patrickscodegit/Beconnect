<?php

namespace App\Filament\Pages;

use App\Models\RobawsArticleCache;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class ArticleSyncProgress extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    
    protected static ?string $navigationLabel = 'Sync Progress';
    
    protected static ?string $navigationGroup = 'Quotation System';
    
    protected static ?int $navigationSort = 13;
    
    protected static string $view = 'filament.pages.article-sync-progress';
    
    protected static ?string $title = 'Article Sync Progress';
    
    // Auto-refresh every 5 seconds while sync is running
    protected $listeners = ['refreshProgress' => '$refresh'];
    
    public function mount(): void
    {
        // Start auto-refresh via Livewire polling
    }
    
    public function getQueueStats(): array
    {
        return [
            'pending_jobs' => DB::table('jobs')->count(),
            'article_jobs' => DB::table('jobs')->where('queue', 'article-metadata')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];
    }
    
    public function getFieldStats(): array
    {
        $total = RobawsArticleCache::count();
        
        return [
            'total' => $total,
            'parent_items' => RobawsArticleCache::where('is_parent_item', true)->count(),
            'with_commodity' => RobawsArticleCache::whereNotNull('commodity_type')->count(),
            'with_pod_code' => RobawsArticleCache::whereNotNull('pod_code')->count(),
            'with_pol_terminal' => RobawsArticleCache::whereNotNull('pol_terminal')->count(),
            'with_shipping_line' => RobawsArticleCache::whereNotNull('shipping_line')->count(),
        ];
    }
    
    public function getRecentArticles(): array
    {
        return RobawsArticleCache::orderBy('updated_at', 'desc')
            ->take(10)
            ->get()
            ->map(fn($article) => [
                'name' => $article->article_name,
                'updated_at' => $article->updated_at,
                'is_parent' => $article->is_parent_item,
                'commodity_type' => $article->commodity_type,
                'pod_code' => $article->pod_code,
            ])
            ->toArray();
    }
    
    public function getSyncStatus(): string
    {
        $stats = $this->getQueueStats();
        
        if ($stats['pending_jobs'] > 0 || $stats['article_jobs'] > 0) {
            return 'running';
        }
        
        $fieldStats = $this->getFieldStats();
        $completionRate = ($fieldStats['with_commodity'] / $fieldStats['total']) * 100;
        
        if ($completionRate > 80 && $fieldStats['parent_items'] > 0) {
            return 'complete';
        }
        
        return 'idle';
    }
    
    public function getProgressPercentage(): int
    {
        $fieldStats = $this->getFieldStats();
        return round(($fieldStats['with_commodity'] / $fieldStats['total']) * 100);
    }
    
    public function getEstimatedTimeRemaining(): ?string
    {
        $stats = $this->getQueueStats();
        $pendingJobs = $stats['pending_jobs'];
        
        if ($pendingJobs === 0) {
            return null;
        }
        
        // Each job takes ~2 seconds (rate limit delay)
        $secondsRemaining = $pendingJobs * 2;
        $minutesRemaining = ceil($secondsRemaining / 60);
        
        if ($minutesRemaining < 60) {
            return "{$minutesRemaining} minutes";
        }
        
        $hours = floor($minutesRemaining / 60);
        $minutes = $minutesRemaining % 60;
        return "{$hours}h {$minutes}m";
    }
}


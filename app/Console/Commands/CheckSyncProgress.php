<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckSyncProgress extends Command
{
    protected $signature = 'articles:check-sync-progress';

    protected $description = 'Check progress of article sync operations';

    public function handle()
    {
        $this->info('📊 ARTICLE SYNC PROGRESS CHECK');
        $this->info('==============================');
        $this->newLine();
        
        // Check queue status
        $this->info('🔄 QUEUE STATUS:');
        $pendingJobs = DB::table('jobs')->where('queue', 'article-metadata')->count();
        $totalPendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['Article Metadata Jobs (Pending)', $pendingJobs],
                ['All Pending Jobs', $totalPendingJobs],
                ['Failed Jobs', $failedJobs],
            ]
        );
        $this->newLine();
        
        // Check article field population
        $this->info('📦 FIELD POPULATION STATUS:');
        $total = RobawsArticleCache::count();
        $withParent = RobawsArticleCache::where('is_parent_item', true)->count();
        $withCommodity = RobawsArticleCache::whereNotNull('commodity_type')->count();
        $withPodCode = RobawsArticleCache::whereNotNull('pod_code')->count();
        $withPolTerminal = RobawsArticleCache::whereNotNull('pol_terminal')->count();
        $withShippingLine = RobawsArticleCache::whereNotNull('shipping_line')->count();
        
        $this->table(
            ['Field', 'Populated', 'Percentage'],
            [
                ['Total Articles', $total, '100%'],
                ['is_parent_item = TRUE', $withParent, round(($withParent/$total)*100, 1) . '%'],
                ['commodity_type', $withCommodity, round(($withCommodity/$total)*100, 1) . '%'],
                ['pod_code', $withPodCode, round(($withPodCode/$total)*100, 1) . '%'],
                ['pol_terminal', $withPolTerminal, round(($withPolTerminal/$total)*100, 1) . '%'],
                ['shipping_line', $withShippingLine, round(($withShippingLine/$total)*100, 1) . '%'],
            ]
        );
        $this->newLine();
        
        // Sync status interpretation
        if ($pendingJobs > 0) {
            $this->info("🔄 SYNC IN PROGRESS: {$pendingJobs} jobs remaining");
            $progress = round((($total - $pendingJobs) / $total) * 100, 1);
            $this->line("   Progress: ~{$progress}%");
        } elseif ($withParent > 0 && $withCommodity > ($total * 0.8)) {
            $this->info('✅ SYNC APPEARS COMPLETE!');
            $this->line("   Parent items: {$withParent}");
            $this->line("   Commodity types: {$withCommodity}");
        } else {
            $this->warn('⚠️ NO SYNC RUNNING - Fields not populated yet');
            $this->line('   Action: Click "Sync Extra Fields" button in admin panel');
        }
        
        $this->newLine();
        
        // Show sample of recent updates
        $recentlyUpdated = RobawsArticleCache::orderBy('updated_at', 'desc')->take(3)->get();
        
        if ($recentlyUpdated->count() > 0) {
            $this->info('📝 RECENTLY UPDATED ARTICLES:');
            foreach ($recentlyUpdated as $article) {
                $this->line('  • ' . substr($article->article_name, 0, 50) . '...');
                $this->line('    Updated: ' . $article->updated_at->diffForHumans());
                $this->line('    Parent: ' . ($article->is_parent_item ? 'YES' : 'NO') . 
                           ', Commodity: ' . ($article->commodity_type ?? 'NULL'));
            }
        }
        
        return Command::SUCCESS;
    }
}


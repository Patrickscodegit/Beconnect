<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MarkSallaumAsParent extends Command
{
    protected $signature = 'articles:mark-sallaum-parent
                          {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Mark all Sallaum main route articles as parent items';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }
        
        $this->info('🔍 Finding Sallaum articles...');
        
        // Find Sallaum articles that should be parent items
        // Exclude surcharges and composite items
        $sallaumArticles = RobawsArticleCache::where(function ($query) {
                $query->where('article_name', 'LIKE', '%Sallaum%')
                      ->orWhere('shipping_line', 'LIKE', '%SALLAUM%');
            })
            ->where('is_surcharge', false)
            ->where(function ($query) {
                // Main route articles typically have POL and POD in the name
                $query->where('article_name', 'LIKE', '%Seafreight%')
                      ->orWhere('article_name', 'LIKE', '%BIG VAN%')
                      ->orWhere('article_name', 'LIKE', '%SMALL VAN%')
                      ->orWhere('article_name', 'LIKE', '%CAR%')
                      ->orWhere('article_name', 'LIKE', '%LM%')
                      ->orWhere('article_name', 'LIKE', '%TRUCK%');
            })
            ->get();
        
        $total = $sallaumArticles->count();
        $this->info("📊 Found {$total} Sallaum articles to update");
        $this->newLine();
        
        if ($total === 0) {
            $this->warn('No articles found to update');
            return Command::SUCCESS;
        }
        
        // Show sample of what will be updated
        $this->info('Sample articles that will be marked as parent items:');
        foreach ($sallaumArticles->take(5) as $article) {
            $this->line('  • ' . $article->article_name);
        }
        
        if ($total > 5) {
            $this->line("  ... and " . ($total - 5) . " more");
        }
        
        $this->newLine();
        
        if ($dryRun) {
            $this->info('✅ Dry run complete - no changes made');
            return Command::SUCCESS;
        }
        
        if (!$this->confirm('Mark these articles as parent items?', true)) {
            $this->warn('Operation cancelled');
            return Command::SUCCESS;
        }
        
        $this->info('🔄 Updating articles...');
        $progressBar = $this->output->createProgressBar($total);
        
        $updated = 0;
        foreach ($sallaumArticles as $article) {
            try {
                $article->update(['is_parent_item' => true]);
                $updated++;
            } catch (\Exception $e) {
                $this->error('Failed to update article: ' . $article->id);
                Log::error('Failed to mark article as parent', [
                    'article_id' => $article->id,
                    'error' => $e->getMessage()
                ]);
            }
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->newLine(2);
        
        $this->info("✅ Updated {$updated}/{$total} articles as parent items");
        
        // Show updated stats
        $parentCount = RobawsArticleCache::where('is_parent_item', true)->count();
        $this->newLine();
        $this->info("🎯 Total articles marked as parent items: {$parentCount}");
        
        return Command::SUCCESS;
    }
}


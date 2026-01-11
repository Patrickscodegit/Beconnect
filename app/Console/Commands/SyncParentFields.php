<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncParentFields extends Command
{
    protected $signature = 'articles:sync-parent-fields
                          {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Sync is_parent_article field to match is_parent_item (source of truth) for all articles';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $this->info('🔍 Finding articles with mismatched parent fields...');
        $this->newLine();

        // Find articles where is_parent_item and is_parent_article don't match
        // Handle NULL values: treat NULL is_parent_item as false
        $mismatched = RobawsArticleCache::where(function ($query) {
            $query->where(function ($q) {
                // is_parent_item is true but is_parent_article is false or null
                $q->where('is_parent_item', true)
                  ->where(function ($qq) {
                      $qq->where('is_parent_article', false)
                         ->orWhereNull('is_parent_article');
                  });
            })->orWhere(function ($q) {
                // is_parent_item is false/null but is_parent_article is true
                $q->where(function ($qq) {
                    $qq->where('is_parent_item', false)
                       ->orWhereNull('is_parent_item');
                })->where('is_parent_article', true);
            });
        })->get();

        $total = $mismatched->count();
        $this->info("📊 Found {$total} articles with mismatched parent fields");
        $this->newLine();

        if ($total === 0) {
            $this->info('✅ All articles are already in sync!');
            return Command::SUCCESS;
        }

        // Show breakdown
        $trueToFalse = $mismatched->filter(function ($article) {
            return $article->is_parent_item === true && ($article->is_parent_article === false || $article->is_parent_article === null);
        })->count();

        $falseToTrue = $mismatched->filter(function ($article) {
            return ($article->is_parent_item === false || $article->is_parent_item === null) && $article->is_parent_article === true;
        })->count();

        $this->table(
            ['Change Type', 'Count'],
            [
                ['is_parent_item=true → is_parent_article=true', $trueToFalse],
                ['is_parent_item=false/null → is_parent_article=false', $falseToTrue],
                ['Total to fix', $total],
            ]
        );
        $this->newLine();

        if ($dryRun) {
            // Show sample articles
            $this->info('📋 Sample articles that would be updated (first 10):');
            $this->newLine();
            $sample = $mismatched->take(10);
            $rows = $sample->map(function ($article) {
                return [
                    $article->id,
                    $article->robaws_article_id,
                    substr($article->article_name, 0, 50),
                    $article->is_parent_item ? 'TRUE' : 'FALSE/NULL',
                    $article->is_parent_article ? 'TRUE' : 'FALSE/NULL',
                    $article->is_parent_item ?? false ? 'TRUE' : 'FALSE',
                ];
            })->toArray();

            $this->table(
                ['ID', 'Robaws ID', 'Article Name', 'Current is_parent_item', 'Current is_parent_article', 'New is_parent_article'],
                $rows
            );
            $this->newLine();
            $this->warn("Run without --dry-run to apply these changes");
            return Command::SUCCESS;
        }

        // Apply changes
        $this->info('🔄 Syncing fields...');
        $this->newLine();

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->setFormat('verbose');
        $progressBar->start();

        $updated = 0;
        $failed = 0;

        foreach ($mismatched as $article) {
            try {
                // Sync: is_parent_article = is_parent_item ?? false
                $newValue = $article->is_parent_item ?? false;
                
                if ($article->is_parent_article !== $newValue) {
                    $article->is_parent_article = $newValue;
                    $article->save();
                    $updated++;
                }
            } catch (\Exception $e) {
                $failed++;
                $this->newLine();
                $this->error("Failed to update article ID {$article->id}: " . $e->getMessage());
            }
            
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Show results
        $this->info('✅ Sync completed!');
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Articles updated', $updated],
                ['Articles failed', $failed],
                ['Total processed', $total],
            ]
        );

        // Verify sync
        $this->newLine();
        $this->info('🔍 Verifying sync...');
        $stillMismatched = RobawsArticleCache::where(function ($query) {
            $query->where(function ($q) {
                $q->where('is_parent_item', true)
                  ->where(function ($qq) {
                      $qq->where('is_parent_article', false)
                         ->orWhereNull('is_parent_article');
                  });
            })->orWhere(function ($q) {
                $q->where(function ($qq) {
                    $qq->where('is_parent_item', false)
                       ->orWhereNull('is_parent_item');
                })->where('is_parent_article', true);
            });
        })->count();

        if ($stillMismatched === 0) {
            $this->info('✅ All articles are now in sync!');
        } else {
            $this->warn("⚠️  {$stillMismatched} articles are still mismatched (may need manual review)");
        }

        return Command::SUCCESS;
    }
}

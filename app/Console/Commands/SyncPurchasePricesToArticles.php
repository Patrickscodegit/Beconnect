<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncPurchasePricesToArticles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purchase-prices:sync-to-articles 
                            {--force : Force sync even if already synced}
                            {--dry-run : Show what would be synced without making changes}
                            {--missing-only : Only sync articles missing purchase_price_breakdown}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync purchase prices from CarrierPurchaseTariff to RobawsArticleCache articles';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $syncService = app(\App\Services\Pricing\PurchasePriceSyncService::class);
        $dryRun = $this->option('dry-run');
        $missingOnly = $this->option('missing-only');
        
        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }
        
        $this->info('Starting purchase price sync for all articles...');
        
        // Get all unique article IDs that have purchase tariffs
        $query = \App\Models\CarrierPurchaseTariff::query()
            ->join('carrier_article_mappings', 'carrier_purchase_tariffs.carrier_article_mapping_id', '=', 'carrier_article_mappings.id')
            ->distinct()
            ->select('carrier_article_mappings.article_id');
        
        $articleIds = $query->pluck('carrier_article_mappings.article_id')
            ->filter()
            ->unique();
        
        // Filter to only articles missing breakdown if requested
        if ($missingOnly) {
            $articlesWithBreakdown = \App\Models\RobawsArticleCache::whereNotNull('purchase_price_breakdown')
                ->whereRaw("purchase_price_breakdown != 'null'")
                ->whereRaw("purchase_price_breakdown != '[]'")
                ->pluck('id')
                ->toArray();
            
            $articleIds = $articleIds->reject(fn ($id) => in_array($id, $articlesWithBreakdown));
            
            $this->info("Filtering to articles missing purchase_price_breakdown...");
        }
        
        $total = $articleIds->count();
        
        if ($total === 0) {
            $this->warn('No articles found' . ($missingOnly ? ' missing purchase_price_breakdown' : '') . ' with purchase tariffs.');
            return 0;
        }
        
        $this->info("Found {$total} articles" . ($missingOnly ? ' missing purchase_price_breakdown' : '') . " with purchase tariffs.");
        $this->newLine();
        
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        
        $synced = 0;
        $errors = 0;
        $skipped = 0;
        $breakdownsAdded = 0;
        $costPricesUpdated = 0;
        
        foreach ($articleIds as $articleId) {
            try {
                $article = \App\Models\RobawsArticleCache::find($articleId);
                if (!$article) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }
                
                $hadBreakdown = !empty($article->purchase_price_breakdown);
                $oldCostPrice = $article->cost_price;
                
                if (!$dryRun) {
                    $syncService->syncActiveTariffForArticle($article);
                    $article->refresh();
                    
                    // Count statistics
                    if (!$hadBreakdown && !empty($article->purchase_price_breakdown)) {
                        $breakdownsAdded++;
                    }
                    if ($oldCostPrice != $article->cost_price) {
                        $costPricesUpdated++;
                    }
                } else {
                    // In dry-run, just check if breakdown is missing
                    if (!$hadBreakdown) {
                        $breakdownsAdded++;
                    }
                }
                
                $synced++;
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("Error syncing article ID {$articleId}: " . $e->getMessage());
            }
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine(2);
        
        if ($dryRun) {
            $this->info('✅ DRY RUN COMPLETE - Summary:');
        } else {
            $this->info('✅ Sync completed!');
        }
        $this->newLine();
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['Articles processed', $synced],
                ['Breakdowns added', $breakdownsAdded],
                ['Cost prices updated', $costPricesUpdated],
                ['Errors', $errors],
                ['Skipped', $skipped],
            ]
        );
        
        if ($dryRun) {
            $this->newLine();
            $this->warn('Run without --dry-run to apply these changes');
        }
        
        return 0;
    }
}

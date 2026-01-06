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
    protected $signature = 'purchase-prices:sync-to-articles {--force : Force sync even if already synced}';

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
        
        $this->info('Starting purchase price sync for all articles...');
        
        // Get all unique article IDs that have purchase tariffs
        $articleIds = \App\Models\CarrierPurchaseTariff::query()
            ->join('carrier_article_mappings', 'carrier_purchase_tariffs.carrier_article_mapping_id', '=', 'carrier_article_mappings.id')
            ->distinct()
            ->pluck('carrier_article_mappings.article_id')
            ->filter()
            ->unique();
        
        $total = $articleIds->count();
        $this->info("Found {$total} articles with purchase tariffs.");
        
        if ($total === 0) {
            $this->warn('No articles found with purchase tariffs.');
            return 0;
        }
        
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        
        $synced = 0;
        $errors = 0;
        
        foreach ($articleIds as $articleId) {
            try {
                $article = \App\Models\RobawsArticleCache::find($articleId);
                if ($article) {
                    $syncService->syncActiveTariffForArticle($article);
                    $synced++;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("Error syncing article ID {$articleId}: " . $e->getMessage());
            }
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine();
        $this->info("Sync completed!");
        $this->info("  Synced: {$synced}");
        if ($errors > 0) {
            $this->warn("  Errors: {$errors}");
        }
        
        return 0;
    }
}

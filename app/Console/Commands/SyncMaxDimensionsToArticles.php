<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncMaxDimensionsToArticles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'max-dimensions:sync-to-articles 
                            {--commodity-type= : Filter by commodity type (e.g., Car, Small Van)}
                            {--dry-run : Show what would be synced without making changes}
                            {--missing-only : Only sync articles missing max_dimensions_breakdown}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync max dimensions from CarrierAcceptanceRule to RobawsArticleCache articles';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $syncService = app(\App\Services\CarrierRules\MaxDimensionsSyncService::class);
        $dryRun = $this->option('dry-run');
        $missingOnly = $this->option('missing-only');
        $commodityType = $this->option('commodity-type');
        
        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }
        
        $this->info('Starting max dimensions sync for all articles...');
        
        // Get all articles that have carrier, POD port, and commodity_type
        $query = \App\Models\RobawsArticleCache::query()
            ->whereNotNull('shipping_carrier_id')
            ->whereNotNull('commodity_type');
        
        // Filter by commodity type if specified
        if ($commodityType) {
            $query->where('commodity_type', $commodityType);
            $this->info("Filtering to commodity type: {$commodityType}");
        }
        
        // Filter to only articles missing breakdown if requested
        if ($missingOnly) {
            $driver = \DB::getDriverName();
            if ($driver === 'pgsql') {
                $articlesWithBreakdown = \App\Models\RobawsArticleCache::whereNotNull('max_dimensions_breakdown')
                    ->whereRaw("max_dimensions_breakdown::text != 'null'")
                    ->whereRaw("max_dimensions_breakdown::text != '[]'")
                    ->pluck('id')
                    ->toArray();
            } else {
                // SQLite/MySQL - check JSON is not null and not empty array
                $articlesWithBreakdown = \App\Models\RobawsArticleCache::whereNotNull('max_dimensions_breakdown')
                    ->whereRaw("json_type(max_dimensions_breakdown) IS NOT NULL")
                    ->whereRaw("json_type(max_dimensions_breakdown) != 'null'")
                    ->pluck('id')
                    ->toArray();
            }
            
            $query->whereNotIn('id', $articlesWithBreakdown);
            
            $this->info("Filtering to articles missing max_dimensions_breakdown...");
        }
        
        $articles = $query->get();
        $total = $articles->count();
        
        if ($total === 0) {
            $this->warn('No articles found' . ($missingOnly ? ' missing max_dimensions_breakdown' : '') . ($commodityType ? " with commodity type {$commodityType}" : '') . '.');
            return 0;
        }
        
        $this->info("Found {$total} articles" . ($missingOnly ? ' missing max_dimensions_breakdown' : '') . ($commodityType ? " with commodity type {$commodityType}" : '') . '.');
        $this->newLine();
        
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        
        $synced = 0;
        $errors = 0;
        $skipped = 0;
        $breakdownsAdded = 0;
        $breakdownsUpdated = 0;
        
        foreach ($articles as $article) {
            try {
                if (!$article->pod_port_id) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }
                
                $hadBreakdown = !empty($article->max_dimensions_breakdown);
                
                if (!$dryRun) {
                    $syncService->syncActiveRuleForArticle($article);
                    $article->refresh();
                    
                    // Count statistics
                    if (!$hadBreakdown && !empty($article->max_dimensions_breakdown)) {
                        $breakdownsAdded++;
                    } elseif ($hadBreakdown && !empty($article->max_dimensions_breakdown)) {
                        $breakdownsUpdated++;
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
                $this->error("Error syncing article ID {$article->id}: " . $e->getMessage());
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
                ['Breakdowns updated', $breakdownsUpdated],
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

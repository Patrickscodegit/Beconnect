<?php

namespace App\Console\Commands;

use App\Jobs\SyncArticlesMetadataBulkJob;
use App\Models\RobawsArticleCache;
use App\Services\Quotation\RobawsArticlesSyncService;
use Illuminate\Console\Command;

class SyncRobawsArticles extends Command
{
    protected $signature = 'robaws:sync-articles 
                            {--rebuild : Clear and rebuild entire cache}
                            {--metadata-only : Only sync metadata for existing articles}
                            {--skip-metadata : Skip automatic metadata sync after article sync}';
    
    protected $description = 'Sync articles from Robaws Articles API';

    public function handle(RobawsArticlesSyncService $syncService): int
    {
        try {
            // Option 1: Metadata only (no article sync)
            if ($this->option('metadata-only')) {
                $this->info('Syncing metadata for all existing articles...');
                $totalArticles = RobawsArticleCache::count();
                
                SyncArticlesMetadataBulkJob::dispatch('all');
                
                $this->info("✅ Dispatched metadata sync for {$totalArticles} articles!");
                $this->comment('💡 Run: php artisan queue:work --queue=article-metadata');
                return Command::SUCCESS;
            }
            
            // Option 2: Full article sync (with or without metadata)
            $this->info('Starting Robaws articles sync...');
            
            if ($this->option('rebuild')) {
                $this->warn('Rebuilding entire article cache...');
                $result = $syncService->rebuildCache();
            } else {
                $result = $syncService->sync();
            }
            
            if ($result['success']) {
                $this->info('✅ Article sync completed successfully!');
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Total Articles', $result['total']],
                        ['Synced', $result['synced']],
                        ['Errors', $result['errors']],
                    ]
                );
                
                // Automatically queue metadata sync (unless --skip-metadata flag)
                if (!$this->option('skip-metadata')) {
                    $this->newLine();
                    $this->info('🔄 Queuing metadata sync for all articles...');
                    
                    SyncArticlesMetadataBulkJob::dispatch('all');
                    
                    $this->info("✅ Dispatched metadata sync for {$result['total']} articles!");
                    $this->comment('💡 Metadata will populate in background');
                    $this->comment('💡 Run: php artisan queue:work --queue=article-metadata');
                } else {
                    $this->comment('⏭️  Skipped metadata sync (--skip-metadata flag)');
                }
                
                return Command::SUCCESS;
            }
            
            $this->error('❌ Sync failed: ' . ($result['error'] ?? 'Unknown error'));
            return Command::FAILURE;
            
        } catch (\Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

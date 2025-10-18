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
                            {--incremental : Only sync articles changed since last sync (recommended for scheduled runs)}
                            {--metadata-only : Only sync metadata for existing articles}
                            {--skip-metadata : Skip automatic metadata sync after article sync}
                            {--use-api : Use Robaws API for metadata (slower, more complete data)}';
    
    protected $description = 'Sync articles from Robaws Articles API';

    public function handle(RobawsArticlesSyncService $syncService): int
    {
        try {
            // Option 1: Incremental sync (recommended for scheduled runs)
            if ($this->option('incremental')) {
                $this->info('🔄 Starting incremental sync (only changed articles)...');
                
                $result = $syncService->syncIncremental();
                
                $this->info("✅ Incremental sync completed!");
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Total Modified', $result['total']],
                        ['Synced', $result['synced']],
                        ['Errors', $result['errors']],
                        ['Last Sync', $result['last_sync'] ?? 'N/A'],
                    ]
                );
                
                return Command::SUCCESS;
            }
            
            // Option 2: Metadata only (no article sync)
            if ($this->option('metadata-only')) {
                $this->info('Syncing metadata for all existing articles...');
                
                // Pass useApi flag to service
                $useApi = $this->option('use-api');
                
                if ($useApi) {
                    $this->warn('⚠️  Using API calls (may hit rate limits and be slow)...');
                } else {
                    $this->info('ℹ️  Using fast extraction (no API calls, except for parent items)');
                }
                
                $result = $syncService->syncAllMetadata(useApi: $useApi);
                
                $this->info("✅ Metadata sync completed!");
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Total Articles', $result['total']],
                        ['Success', $result['success']],
                        ['Failed', $result['failed']],
                    ]
                );
                return Command::SUCCESS;
            }
            
            // Option 3: Full article sync (with or without metadata)
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
                
                // Automatically sync metadata (unless --skip-metadata flag)
                if (!$this->option('skip-metadata')) {
                    $this->newLine();
                    $this->info('🔄 Syncing metadata for all articles...');
                    
                    $metadataResult = $syncService->syncAllMetadata();
                    
                    $this->info("✅ Metadata sync completed!");
                    $this->table(
                        ['Metric', 'Value'],
                        [
                            ['Total Articles', $metadataResult['total']],
                            ['Success', $metadataResult['success']],
                            ['Failed', $metadataResult['failed']],
                        ]
                    );
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

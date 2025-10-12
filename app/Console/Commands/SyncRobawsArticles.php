<?php

namespace App\Console\Commands;

use App\Services\Quotation\RobawsArticlesSyncService;
use Illuminate\Console\Command;

class SyncRobawsArticles extends Command
{
    protected $signature = 'robaws:sync-articles 
                            {--rebuild : Clear and rebuild entire cache}';
    
    protected $description = 'Sync articles from Robaws Articles API';

    public function handle(RobawsArticlesSyncService $syncService): int
    {
        $this->info('Starting Robaws articles sync...');
        
        try {
            if ($this->option('rebuild')) {
                $this->warn('Rebuilding entire article cache...');
                $result = $syncService->rebuildCache();
            } else {
                $result = $syncService->sync();
            }
            
            if ($result['success']) {
                $this->info('✅ Sync completed successfully!');
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Total Articles', $result['total']],
                        ['Synced', $result['synced']],
                        ['Errors', $result['errors']],
                    ]
                );
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

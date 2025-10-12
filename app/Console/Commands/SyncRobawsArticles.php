<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncRobawsArticles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'robaws:sync-articles {--force : Force sync even if recently synced}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync articles from Robaws API to local cache';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!config('quotation.enabled')) {
            $this->error('Quotation system is disabled');
            return 1;
        }

        $this->info('Starting Robaws article sync...');

        try {
            $articleProvider = app(\App\Services\Robaws\RobawsArticleProvider::class);
            
            $syncedCount = $articleProvider->syncArticles();

            $this->info("✓ Successfully synced {$syncedCount} articles from Robaws");
            
            return 0;

        } catch (\App\Exceptions\RateLimitException $e) {
            $this->error('Rate limit exceeded: ' . $e->getMessage());
            return 1;

        } catch (\Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}

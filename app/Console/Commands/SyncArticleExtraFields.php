<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use App\Services\Robaws\RobawsArticleProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncArticleExtraFields extends Command
{
    protected $signature = 'robaws:sync-extra-fields
                          {--batch-size=50 : Number of articles to process in each batch}
                          {--delay=2 : Delay in seconds between API calls}
                          {--start-from=0 : Start from this article ID (for resuming)}';

    protected $description = 'Sync extra fields (parent item, shipping line, etc.) from Robaws API for all articles';

    public function handle()
    {
        $batchSize = (int) $this->option('batch-size');
        $delay = (int) $this->option('delay');
        $startFrom = (int) $this->option('start-from');

        $this->info('🔄 Starting extra fields sync for all articles...');
        $this->info("⚙️  Batch size: {$batchSize} | Delay: {$delay}s | Start from ID: {$startFrom}");
        $this->newLine();

        $provider = app(RobawsArticleProvider::class);
        
        $query = RobawsArticleCache::query();
        if ($startFrom > 0) {
            $query->where('id', '>=', $startFrom);
        }
        
        $total = $query->count();
        $processed = 0;
        $success = 0;
        $failed = 0;
        $skipped = 0;

        $this->info("📊 Total articles to process: {$total}");
        $this->newLine();

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->setFormat('verbose');

        $query->orderBy('id')->chunk($batchSize, function ($articles) use (
            $provider,
            &$processed,
            &$success,
            &$failed,
            &$skipped,
            $delay,
            $progressBar
        ) {
            foreach ($articles as $article) {
                try {
                    // Fetch full article details from Robaws API
                    $details = $provider->getArticleDetails($article->robaws_article_id);
                    
                    if (!$details) {
                        $skipped++;
                        $progressBar->advance();
                        continue;
                    }

                    // Extract extra fields
                    $updateData = [];
                    
                    if (isset($details['extraFields'])) {
                        $extraFields = $details['extraFields'];
                        
                        // Parent Item checkbox
                        if (isset($extraFields['PARENT ITEM']['booleanValue'])) {
                            $updateData['is_parent_item'] = (bool) $extraFields['PARENT ITEM']['booleanValue'];
                        }
                        
                        // Shipping Line
                        if (isset($extraFields['SHIPPING LINE']['stringValue'])) {
                            $updateData['shipping_line'] = $extraFields['SHIPPING LINE']['stringValue'];
                        }
                        
                        // Service Type
                        if (isset($extraFields['SERVICE TYPE']['stringValue'])) {
                            $updateData['service_type'] = $extraFields['SERVICE TYPE']['stringValue'];
                        }
                        
                        // POL Terminal
                        if (isset($extraFields['POL TERMINAL']['stringValue'])) {
                            $updateData['pol_terminal'] = $extraFields['POL TERMINAL']['stringValue'];
                        }
                        
                        // Update Date
                        if (isset($extraFields['UPDATE DATE']['stringValue'])) {
                            try {
                                $updateData['update_date'] = \Carbon\Carbon::parse($extraFields['UPDATE DATE']['stringValue'])->format('Y-m-d');
                            } catch (\Exception $e) {
                                // Invalid date, skip
                            }
                        }
                        
                        // Validity Date
                        if (isset($extraFields['VALIDITY DATE']['stringValue'])) {
                            try {
                                $updateData['validity_date'] = \Carbon\Carbon::parse($extraFields['VALIDITY DATE']['stringValue'])->format('Y-m-d');
                            } catch (\Exception $e) {
                                // Invalid date, skip
                            }
                        }
                        
                        // Article Info
                        if (isset($extraFields['INFO']['stringValue'])) {
                            $updateData['article_info'] = $extraFields['INFO']['stringValue'];
                        }
                    }

                    // Update article if we have data
                    if (!empty($updateData)) {
                        $article->update($updateData);
                        $success++;
                    } else {
                        $skipped++;
                    }

                } catch (\Exception $e) {
                    $failed++;
                    Log::warning('Failed to sync extra fields for article', [
                        'article_id' => $article->robaws_article_id,
                        'error' => $e->getMessage()
                    ]);
                }

                $processed++;
                $progressBar->advance();
                
                // Delay between requests to respect rate limits
                if ($delay > 0) {
                    usleep($delay * 1000000);
                }
            }
            
            // Log progress after each batch
            Log::info('Extra fields sync batch completed', [
                'processed' => $processed,
                'success' => $success,
                'failed' => $failed,
                'skipped' => $skipped
            ]);
        });

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info('✅ Extra fields sync completed!');
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Processed', $processed],
                ['Successfully Updated', $success],
                ['Failed', $failed],
                ['Skipped', $skipped],
            ]
        );

        // Show parent items count
        $parentCount = RobawsArticleCache::where('is_parent_item', true)->count();
        $this->newLine();
        $this->info("🎯 Total articles marked as parent items: {$parentCount}");

        return Command::SUCCESS;
    }
}


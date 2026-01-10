<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use App\Services\Robaws\RobawsArticleProvider;
use App\Services\Robaws\ArticleSyncEnhancementService;
use App\Services\Robaws\RobawsFieldMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncArticleExtraFields extends Command
{
    protected $signature = 'robaws:sync-extra-fields
                          {--batch-size=50 : Number of articles to process in each batch}
                          {--delay=0.5 : Delay in seconds between API calls (default 0.5s = 2 req/sec, safe for server)}
                          {--start-from=0 : Start from this article ID (for resuming)}';

    protected $description = 'Sync extra fields (parent item, shipping line, commodity type, POD code, etc.) from Robaws API for all articles';

    protected RobawsArticleProvider $provider;
    protected ArticleSyncEnhancementService $enhancementService;
    protected RobawsFieldMapper $fieldMapper;

    public function __construct(
        RobawsArticleProvider $provider,
        ArticleSyncEnhancementService $enhancementService,
        RobawsFieldMapper $fieldMapper
    ) {
        parent::__construct();
        $this->provider = $provider;
        $this->enhancementService = $enhancementService;
        $this->fieldMapper = $fieldMapper;
    }

    public function handle()
    {
        $batchSize = (int) $this->option('batch-size');
        $delay = (int) $this->option('delay');
        $startFrom = (int) $this->option('start-from');

        $this->info('🔄 Starting extra fields sync for all articles...');
        $this->info("⚙️  Batch size: {$batchSize} | Delay: {$delay}s | Start from ID: {$startFrom}");
        $this->newLine();
        
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
                    $details = $this->provider->getArticleDetails($article->robaws_article_id);
                    
                    if (!$details) {
                        $skipped++;
                        $progressBar->advance();
                        continue;
                    }

                    // Extract extra fields
                    $updateData = [];
                    
                    if (isset($details['extraFields'])) {
                        $extraFields = $details['extraFields'];
                        
                        // Parent Item checkbox - Use flexible field mapping
                        $parentItemValue = $this->fieldMapper->getBooleanValue($extraFields, 'parent_item');
                        if ($parentItemValue !== null) {
                            $updateData['is_parent_item'] = $parentItemValue;
                        }
                        
                        // Shipping Line - Use flexible field mapping
                        $shippingLineValue = $this->fieldMapper->getStringValue($extraFields, 'shipping_line');
                        if ($shippingLineValue !== null) {
                            $updateData['shipping_line'] = $shippingLineValue;
                            // Note: applicable_carriers removed - each article has one shipping_line
                            
                            // Try to link to carrier using CarrierLookupService
                            if (empty($updateData['shipping_carrier_id'])) {
                                $carrierLookup = app(\App\Services\Carrier\CarrierLookupService::class);
                                $carrier = $carrierLookup->findByCodeOrName($shippingLineValue);
                                if ($carrier) {
                                    $updateData['shipping_carrier_id'] = $carrier->id;
                                }
                            }
                        }
                        
                        // Service Type - Use flexible field mapping
                        $serviceTypeValue = $this->fieldMapper->getStringValue($extraFields, 'service_type');
                        if ($serviceTypeValue !== null) {
                            // Normalize service type: convert spaces to underscores for consistency
                            // Robaws returns "RORO EXPORT", we need "RORO_EXPORT"
                            $normalizedServiceType = str_replace(' ', '_', strtoupper($serviceTypeValue));
                            $updateData['service_type'] = $normalizedServiceType;
                            $updateData['applicable_services'] = [$normalizedServiceType];
                        }
                        
                        // POL Terminal - Use flexible field mapping
                        $polTerminalValue = $this->fieldMapper->getStringValue($extraFields, 'pol_terminal');
                        if ($polTerminalValue !== null) {
                            $updateData['pol_terminal'] = $polTerminalValue;
                        }
                        
                        // Update Date - Use flexible field mapping
                        $updateDateValue = $this->fieldMapper->getStringValue($extraFields, 'update_date');
                        if ($updateDateValue !== null) {
                            $parsedDate = $this->parseRobawsDate($updateDateValue);
                            if ($parsedDate) {
                                $updateData['update_date'] = $parsedDate;
                            }
                        }
                        
                        // Validity Date - Use flexible field mapping
                        $validityDateValue = $this->fieldMapper->getStringValue($extraFields, 'validity_date');
                        if ($validityDateValue !== null) {
                            $parsedDate = $this->parseRobawsDate($validityDateValue);
                            if ($parsedDate) {
                                $updateData['validity_date'] = $parsedDate;
                            }
                        }
                        
                        // Article Info - Use flexible field mapping
                        $infoValue = $this->fieldMapper->getStringValue($extraFields, 'info');
                        if ($infoValue !== null) {
                            $updateData['article_info'] = $infoValue;
                        }
                    }
                    
                    // Extract enhanced fields for Smart Article Selection
                    try {
                        $updateData['commodity_type'] = $this->enhancementService->extractCommodityType($details);
                        
                        // POL/POD: Try extraFields first, then fall back to parsing article name
                        $polFromApi = $this->fieldMapper->getStringValue($extraFields ?? [], 'pol');
                        $podFromApi = $this->fieldMapper->getStringValue($extraFields ?? [], 'pod');
                        
                        if ($polFromApi) {
                            $updateData['pol'] = $polFromApi;
                        } else {
                            // Parse from article name using ArticleNameParser
                            $parser = app(\App\Services\Robaws\ArticleNameParser::class);
                            $polData = $parser->extractPOL($article->article_name);
                            if ($polData && $polData['formatted']) {
                                $updateData['pol'] = $polData['formatted'];
                            }
                        }
                        
                        if ($podFromApi) {
                            $updateData['pod'] = $podFromApi;
                        } else {
                            // Parse from article name using ArticleNameParser
                            $parser = app(\App\Services\Robaws\ArticleNameParser::class);
                            $podData = $parser->extractPOD($article->article_name);
                            if ($podData && $podData['formatted']) {
                                $updateData['pod'] = $podData['formatted'];
                            }
                        }
                    } catch (\Exception $e) {
                        // Non-critical - continue without enhanced fields
                        Log::debug('Failed to extract enhanced fields for article', [
                            'article_id' => $article->robaws_article_id,
                            'error' => $e->getMessage()
                        ]);
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
    
    // mapShippingLineToCarrierCode() removed - applicable_carriers field removed from articles

    /**
     * Parse date string with multiple format attempts
     * Handles various Robaws date formats like "22/07/25", "23/072025", etc.
     */
    protected function parseRobawsDate(string $dateString): ?string
    {
        // Common Robaws date formats
        $formats = [
            'd/m/y',       // 22/07/25
            'd/m/Y',       // 22/07/2025
            'dmY',         // 23072025
            'd/mY',        // 23/072025
            'Y-m-d',       // 2025-07-22
            'd/m/Y H:i:s', // 22/07/2025 10:30:00
            'Y-m-d H:i:s', // 2025-07-22 10:30:00
        ];
        
        foreach ($formats as $format) {
            try {
                $date = \Carbon\Carbon::createFromFormat($format, $dateString);
                if ($date) {
                    return $date->format('Y-m-d');
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        
        // If all formats fail, try Carbon::parse as last resort
        try {
            return \Carbon\Carbon::parse($dateString)->format('Y-m-d');
        } catch (\Exception $e) {
            Log::debug('Failed to parse Robaws date', [
                'date_string' => $dateString,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}


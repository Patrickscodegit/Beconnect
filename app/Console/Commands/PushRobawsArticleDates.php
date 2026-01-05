<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PushRobawsArticleDates extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'robaws:push-article-dates
                            {--article=* : Article code(s) to update}
                            {--update= : Update date (Y-m-d format)}
                            {--validity= : Validity date (Y-m-d format)}
                            {--sales-price : Push sales price (unit_price) to Robaws}
                            {--dry-run : Show what would be updated}
                            {--sleep-ms=0 : Delay between requests}
                            {--fallback : Use minimal payload format (skip type/group)}
                            {--from-tariffs : Push dates from purchase tariffs instead of article cache}
                            {--force : Force push even if dates appear to match}';

    /**
     * The console command description.
     */
    protected $description = 'Push article dates and optionally sales prices to Robaws API';

    public function handle(RobawsApiClient $client): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $useFallback = (bool) $this->option('fallback');
        $pushSalesPrice = (bool) $this->option('sales-price');
        $fromTariffs = (bool) $this->option('from-tariffs');
        $force = (bool) $this->option('force');
        
        $updateDate = $this->option('update');
        $validityDate = $this->option('validity');
        
        // Validate dates if provided
        if ($updateDate && !$this->validateDate($updateDate, 'update')) {
            return self::INVALID;
        }
        if ($validityDate && !$this->validateDate($validityDate, 'validity')) {
            return self::INVALID;
        }
        
        $articleCodes = collect($this->option('article'))
            ->filter()
            ->flatMap(fn ($value) => preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY))
            ->unique()
            ->values();
        
        $query = RobawsArticleCache::query()
            ->whereNotNull('robaws_article_id');
        
        if ($articleCodes->isNotEmpty()) {
            $query->whereIn('article_code', $articleCodes);
        } else {
            // If no specific articles provided, only push articles that have override dates set
            // (these are the ones we synced from tariffs)
            if (!$updateDate && !$validityDate && !$fromTariffs) {
                $query->where(function ($q) {
                    $q->whereNotNull('update_date_override')
                      ->orWhereNotNull('validity_date_override');
                });
            }
        }
        
        // If dates provided via options, filter articles that have those dates
        // Otherwise, use effective dates from article cache
        if (!$fromTariffs) {
            if ($updateDate) {
                // Filter by effective update date
                $query->where(function ($q) use ($updateDate) {
                    $q->where('update_date_override', $updateDate)
                      ->orWhere(function ($q2) use ($updateDate) {
                          $q2->whereNull('update_date_override')
                             ->where('update_date', $updateDate);
                      });
                });
            }
            if ($validityDate) {
                // Filter by effective validity date
                $query->where(function ($q) use ($validityDate) {
                    $q->where('validity_date_override', $validityDate)
                      ->orWhere(function ($q2) use ($validityDate) {
                          $q2->whereNull('validity_date_override')
                             ->where('validity_date', $validityDate);
                      });
                });
            }
        }
        
        $total = (clone $query)->count();
        if ($total === 0) {
            $this->warn('No articles found to update.');
            return self::SUCCESS;
        }
        
        $this->info(sprintf(
            'Preparing to %s dates for %d article(s).',
            $dryRun ? 'simulate pushing' : 'push',
            $total
        ));
        
        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();
        
        $processed = 0;
        $success = 0;
        $failed = 0;
        $errors = [];
        
        $query->orderBy('id')->chunkById(50, function (Collection $articles) use (
            $client,
            $dryRun,
            $progressBar,
            $sleepMs,
            $useFallback,
            $pushSalesPrice,
            $updateDate,
            $validityDate,
            $fromTariffs,
            $force,
            &$processed,
            &$success,
            &$failed,
            &$errors
        ) {
            foreach ($articles as $article) {
                $processed++;
                
                // Get dates to push
                $updateDateToPush = $updateDate;
                $validityDateToPush = $validityDate;
                
                if (!$updateDateToPush || !$validityDateToPush) {
                    // Use effective dates from article
                    $updateDateCarbon = $article->effective_update_date;
                    $validityDateCarbon = $article->effective_validity_date;
                    
                    if (!$updateDateToPush && $updateDateCarbon) {
                        $updateDateToPush = $updateDateCarbon->format('Y-m-d');
                    }
                    if (!$validityDateToPush && $validityDateCarbon) {
                        $validityDateToPush = $validityDateCarbon->format('Y-m-d');
                    }
                }
                
                // Build payload
                $extraFields = [];
                
                if ($updateDateToPush) {
                    // Robaws stores dates as TEXT with stringValue, not DATE with dateValue
                    // Format: MM/DD/YY (e.g., "01/05/26" for 2026-01-05)
                    // NOTE: User reports dates don't match - may need to verify format
                    $dateCarbon = \Carbon\Carbon::parse($updateDateToPush);
                    // Try using 4-digit year to avoid ambiguity: MM/DD/YYYY
                    $formattedDate = $dateCarbon->format('m/d/Y'); // MM/DD/YYYY format (4-digit year)
                    
                    if ($useFallback) {
                        $extraFields['UPDATE DATE'] = ['stringValue' => $formattedDate];
                    } else {
                        $extraFields['UPDATE DATE'] = [
                            'type' => 'TEXT',
                            'group' => 'IMPORTANT INFO',
                            'stringValue' => $formattedDate,
                        ];
                    }
                }
                
                if ($validityDateToPush) {
                    // Robaws stores dates as TEXT with stringValue, not DATE with dateValue
                    // Format: MM/DD/YY (e.g., "01/12/26" for 2026-01-12)
                    // NOTE: User reports dates don't match - may need to verify format
                    $dateCarbon = \Carbon\Carbon::parse($validityDateToPush);
                    // Try using 4-digit year to avoid ambiguity: MM/DD/YYYY
                    $formattedDate = $dateCarbon->format('m/d/Y'); // MM/DD/YYYY format (4-digit year)
                    
                    if ($useFallback) {
                        $extraFields['VALIDITY DATE'] = ['stringValue' => $formattedDate];
                    } else {
                        $extraFields['VALIDITY DATE'] = [
                            'type' => 'TEXT',
                            'group' => 'IMPORTANT INFO',
                            'stringValue' => $formattedDate,
                        ];
                    }
                }
                
                // Add sales price if requested
                if ($pushSalesPrice && $article->unit_price !== null) {
                    // Note: Sales price field name needs to be confirmed with Robaws API
                    // This is a placeholder - adjust based on actual API requirements
                    $extraFields['SALES PRICE'] = [
                        'type' => 'NUMBER',
                        'group' => 'ARTICLE INFO',
                        'numberValue' => (float) $article->unit_price,
                    ];
                }
                
                if (empty($extraFields)) {
                    $failed++;
                    $errors[] = [
                        'robaws_article_id' => $article->robaws_article_id,
                        'article_code' => $article->article_code,
                        'status' => 0,
                        'error' => 'No dates or prices available to push for this article.',
                    ];
                    Log::warning('No dates available to push for Robaws article', end($errors));
                    $progressBar->advance();
                    continue;
                }
                
                $payload = ['extraFields' => $extraFields];
                
                // Fetch current state from Robaws before pushing
                $currentState = null;
                $skipPush = false;
                $pushingUpdateDateFormatted = $updateDateToPush ? \Carbon\Carbon::parse($updateDateToPush)->format('m/d/Y') : null;
                $pushingValidityDateFormatted = $validityDateToPush ? \Carbon\Carbon::parse($validityDateToPush)->format('m/d/Y') : null;
                
                if (!$dryRun) {
                    try {
                        $currentResult = $client->getArticle($article->robaws_article_id);
                        if ($currentResult['success'] ?? false) {
                            $currentExtraFields = $currentResult['data']['extraFields'] ?? [];
                            $currentState = [
                                'updateDate' => $currentExtraFields['UPDATE DATE']['stringValue'] ?? null,
                                'validityDate' => $currentExtraFields['VALIDITY DATE']['stringValue'] ?? null,
                            ];
                            
                            // Normalize Robaws dates to 4-digit year for comparison
                            $currentUpdateDateNormalized = $currentState['updateDate'] ? 
                                (strlen($currentState['updateDate']) === 8 ? 
                                    \Carbon\Carbon::createFromFormat('m/d/y', $currentState['updateDate'])->format('m/d/Y') : 
                                    $currentState['updateDate']) : null;
                            $currentValidityDateNormalized = $currentState['validityDate'] ? 
                                (strlen($currentState['validityDate']) === 8 ? 
                                    \Carbon\Carbon::createFromFormat('m/d/y', $currentState['validityDate'])->format('m/d/Y') : 
                                    $currentState['validityDate']) : null;
                            
                            if (!$force && $currentUpdateDateNormalized === $pushingUpdateDateFormatted && 
                                $currentValidityDateNormalized === $pushingValidityDateFormatted) {
                                $skipPush = true;
                            }
                        }
                    } catch (\Exception $e) {
                        // Ignore errors fetching current state
                    }
                }
                
                // Skip if values are already the same in Robaws
                if ($skipPush && !$dryRun) {
                    $this->warn("Skipping article {$article->article_code}: dates already match in Robaws");
                    $progressBar->advance();
                    continue;
                }
                
                if ($dryRun) {
                    $success++;
                    Log::info('Dry-run: would push Robaws article dates', [
                        'robaws_article_id' => $article->robaws_article_id,
                        'article_code' => $article->article_code,
                        'update_date' => $updateDateToPush,
                        'validity_date' => $validityDateToPush,
                        'fields' => array_keys($extraFields),
                    ]);
                } else {
                    $response = $client->updateArticle($article->robaws_article_id, $payload);
                    
                    // Verify the update by fetching the article again
                    $verifyState = null;
                    $datesMatch = false;
                    if ($response['success'] ?? false) {
                        try {
                            // Wait longer for Robaws to process the update (3 seconds)
                            sleep(3);
                            $verifyResult = $client->getArticle($article->robaws_article_id);
                            if ($verifyResult['success'] ?? false) {
                                $verifyExtraFields = $verifyResult['data']['extraFields'] ?? [];
                                $verifyState = [
                                    'updateDate' => $verifyExtraFields['UPDATE DATE']['stringValue'] ?? null,
                                    'validityDate' => $verifyExtraFields['VALIDITY DATE']['stringValue'] ?? null,
                                ];
                                
                                // Check if dates actually match what we sent
                                $datesMatch = ($verifyState['updateDate'] === $pushingUpdateDateFormatted) && 
                                             ($verifyState['validityDate'] === $pushingValidityDateFormatted);
                                
                                if (!$datesMatch) {
                                    // Show warning to user
                                    $this->warn("⚠️  Warning: Dates may not have updated in Robaws. Expected: {$pushingUpdateDateFormatted}/{$pushingValidityDateFormatted}, Got: {$verifyState['updateDate']}/{$verifyState['validityDate']}");
                                } else {
                                    // Show success message
                                    $this->info("✅ Verified: Dates updated in Robaws ({$verifyState['updateDate']}/{$verifyState['validityDate']})");
                                }
                            }
                        } catch (\Exception $e) {
                            // Ignore verification errors
                        }
                    }
                    
                    if ($response['success'] ?? false) {
                        $success++;
                        
                        // Update local cache with what was pushed
                        $updates = [
                            'last_pushed_dates_at' => now(),
                        ];
                        
                        if ($updateDateToPush) {
                            $updates['last_pushed_update_date'] = \Carbon\Carbon::parse($updateDateToPush);
                            // Optionally sync back to base fields
                            $updates['update_date'] = \Carbon\Carbon::parse($updateDateToPush);
                        }
                        if ($validityDateToPush) {
                            $updates['last_pushed_validity_date'] = \Carbon\Carbon::parse($validityDateToPush);
                            // Optionally sync back to base fields
                            $updates['validity_date'] = \Carbon\Carbon::parse($validityDateToPush);
                        }
                        
                        $article->update($updates);
                    } else {
                        $failed++;
                        $errorMessage = $response['error'] ?? 'Unknown error';
                        $status = $response['status'] ?? 0;
                        
                        $errors[] = [
                            'robaws_article_id' => $article->robaws_article_id,
                            'article_code' => $article->article_code,
                            'status' => $status,
                            'error' => $errorMessage,
                            'fields' => array_keys($extraFields),
                        ];
                        
                        Log::warning('Failed to push Robaws article dates', end($errors));
                        
                        // Show error to user
                        $this->error("Failed to push dates for {$article->article_code}: [{$status}] {$errorMessage}");
                    }
                }
                
                $progressBar->advance();
                
                if ($sleepMs > 0 && !$dryRun) {
                    usleep($sleepMs * 1000);
                }
            }
            
            return true;
        });
        
        $progressBar->finish();
        $this->newLine(2);
        
        $this->table(
            ['Metric', 'Value'],
            [
                ['Processed', $processed],
                ['Success', $success],
                ['Failed', $failed],
                ['Mode', $dryRun ? 'DRY-RUN' : 'LIVE'],
            ]
        );
        
        if (!empty($errors)) {
            $this->warn('Some articles failed to update:');
            foreach ($errors as $error) {
                $this->line(sprintf(
                    ' - #%s (%s): [%s] %s',
                    $error['robaws_article_id'],
                    $error['article_code'],
                    $error['status'],
                    $error['error']
                ));
            }
        }
        
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
    
    /**
     * Validate date format and year >= 1900
     */
    private function validateDate(string $date, string $fieldName): bool
    {
        try {
            $parsed = \Carbon\Carbon::parse($date);
            // Reject invalid years (same validation as normalizeRobawsDate)
            if ($parsed->year < 1900) {
                $this->error("Invalid {$fieldName}: year must be >= 1900");
                return false;
            }
            return true;
        } catch (\Exception $e) {
            $this->error("Invalid {$fieldName} format: {$date}. Expected Y-m-d (e.g., 2026-01-01)");
            return false;
        }
    }
}


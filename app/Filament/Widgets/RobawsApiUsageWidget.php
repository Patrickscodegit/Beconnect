<?php

namespace App\Filament\Widgets;

use App\Services\Export\Clients\RobawsApiClient;
use App\Models\RobawsWebhookLog;
use App\Models\RobawsArticleCache;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RobawsApiUsageWidget extends BaseWidget
{
    protected static ?int $sort = 1;
    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        try {
            $client = app(RobawsApiClient::class);
            $dailyLimit = $client->getDailyLimit();
            $dailyRemaining = $client->getDailyRemaining();
            $used = $dailyLimit - $dailyRemaining;
            $percentageUsed = round(($used / $dailyLimit) * 100, 1);
            
            // Calculate optimization savings
            // Webhooks: 2 API calls saved per webhook (processArticle + syncMetadata)
            $webhooksLast24h = RobawsWebhookLog::where('created_at', '>=', now()->subDay())
                ->where('status', 'processed')
                ->count();
            $apiCallsSavedFromWebhooks = $webhooksLast24h * 2; // 2 API calls saved per webhook
            
            // Sync optimization: Articles with extraFields don't need API calls
            // Estimate: Before optimization, all articles would need 1 API call each
            // After optimization, only articles without extraFields need API calls
            $totalArticles = RobawsArticleCache::count();
            $articlesWithExtraFields = RobawsArticleCache::where(function($query) {
                $query->whereNotNull('shipping_line')
                    ->orWhereNotNull('transport_mode')
                    ->orWhereNotNull('pol_terminal');
            })->count();
            $articlesWithoutExtraFields = max(0, $totalArticles - $articlesWithExtraFields);
            
            // Estimated API calls saved from sync optimization (rough estimate)
            // Before: All articles needed API calls (1,576 API calls per full sync)
            // After: Only articles without extraFields need API calls (~300 API calls per full sync)
            // Saved: ~1,276 API calls per full sync
            // Note: This is an estimate - actual savings depend on when sync runs
            $estimatedSyncSavingsPerFullSync = max(0, $articlesWithExtraFields);
            
            // Total API calls saved (webhooks only - sync savings are per-sync, not daily)
            // Webhook savings are real-time (2 API calls saved per webhook)
            $totalApiCallsSaved = $apiCallsSavedFromWebhooks;
            
            // Determine color based on usage
            $remainingColor = match(true) {
                $dailyRemaining > 5000 => 'success',
                $dailyRemaining > 2000 => 'warning',
                default => 'danger'
            };
            
            $usedColor = match(true) {
                $percentageUsed < 50 => 'success',
                $percentageUsed < 80 => 'warning',
                default => 'danger'
            };

            return [
                Stat::make('API Calls Remaining', number_format($dailyRemaining))
                    ->description('Out of ' . number_format($dailyLimit) . ' daily quota')
                    ->descriptionIcon('heroicon-m-clock')
                    ->color($remainingColor)
                    ->chart([7, 4, 3, 5, 6, 3, 2]),
                    
                Stat::make('API Calls Used Today', number_format($used))
                    ->description($percentageUsed . '% of daily quota')
                    ->descriptionIcon('heroicon-m-arrow-trending-up')
                    ->color($usedColor),
                    
                Stat::make('Per-Second Limit', $client->getPerSecondLimit() . ' req/sec')
                    ->description('Rate limiting active')
                    ->descriptionIcon('heroicon-m-bolt')
                    ->color('info'),
                    
                Stat::make('API Calls Saved (24h)', number_format($apiCallsSavedFromWebhooks))
                    ->description("From webhook optimization (2 → 0 API calls)")
                    ->descriptionIcon('heroicon-m-bolt')
                    ->color('success'),
                    
                Stat::make('Sync Optimization', "~" . number_format($estimatedSyncSavingsPerFullSync) . " saved/sync")
                    ->description($totalArticles > 0 ? "Articles with extraFields: {$articlesWithExtraFields} / {$totalArticles}" : "No articles synced yet")
                    ->descriptionIcon('heroicon-m-chart-bar')
                    ->color($articlesWithExtraFields > 0 ? 'success' : 'gray'),
            ];
        } catch (\Exception $e) {
            return [
                Stat::make('API Status', 'Unable to fetch')
                    ->description('Error: ' . $e->getMessage())
                    ->descriptionIcon('heroicon-m-exclamation-triangle')
                    ->color('danger'),
            ];
        }
    }
}


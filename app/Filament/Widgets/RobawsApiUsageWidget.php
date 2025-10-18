<?php

namespace App\Filament\Widgets;

use App\Services\Export\Clients\RobawsApiClient;
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


<?php

namespace App\Filament\Widgets;

use App\Models\RobawsWebhookLog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RobawsWebhookStatusWidget extends BaseWidget
{
    protected static ?int $sort = 2;
    
    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        // Get last webhook received
        $lastWebhook = RobawsWebhookLog::latest('created_at')->first();
        
        // Calculate status
        $isActive = false;
        $statusColor = 'danger';
        $statusText = '🔴 No Webhooks';
        $description = 'No webhooks received yet';
        
        if ($lastWebhook) {
            $minutesAgo = $lastWebhook->created_at->diffInMinutes(now());
            $hoursAgo = $lastWebhook->created_at->diffInHours(now());
            $daysAgo = $lastWebhook->created_at->diffInDays(now());
            
            // Consider webhooks active if received within last 24 hours
            if ($minutesAgo < 60) {
                $isActive = true;
                $statusColor = 'success';
                $statusText = '🟢 Webhooks Active';
                $description = "Last webhook: {$minutesAgo} minute(s) ago";
            } elseif ($hoursAgo < 24) {
                $isActive = true;
                $statusColor = 'success';
                $statusText = '🟢 Webhooks Active';
                $description = "Last webhook: {$hoursAgo} hour(s) ago";
            } elseif ($daysAgo < 7) {
                $statusColor = 'warning';
                $statusText = '🟡 Webhooks Stale';
                $description = "Last webhook: {$daysAgo} day(s) ago";
            } else {
                $statusColor = 'danger';
                $statusText = '🔴 Webhooks Down';
                $description = "Last webhook: {$daysAgo} day(s) ago";
            }
        }
        
        // Get webhook stats (last 24 hours)
        $last24h = RobawsWebhookLog::where('created_at', '>=', now()->subDay())->count();
        $processed = RobawsWebhookLog::where('created_at', '>=', now()->subDay())->processed()->count();
        $failed = RobawsWebhookLog::where('created_at', '>=', now()->subDay())->failed()->count();
        
        $successRate = $last24h > 0 ? round(($processed / $last24h) * 100, 1) : 0;
        
        // Calculate average processing time (optimization: should be <100ms)
        $avgProcessingTime = RobawsWebhookLog::where('created_at', '>=', now()->subDay())
            ->whereNotNull('processing_duration_ms')
            ->where('processing_duration_ms', '>', 0)
            ->avg('processing_duration_ms');
        
        $avgProcessingTimeMs = $avgProcessingTime ? round($avgProcessingTime, 0) : 0;
        $avgProcessingTimeSeconds = $avgProcessingTimeMs > 0 ? round($avgProcessingTimeMs / 1000, 2) : 0;
        
        // Calculate API calls saved (optimization: webhooks now use 0 API calls vs 2 before)
        $apiCallsSaved = $last24h * 2; // 2 API calls saved per webhook (processArticle + syncMetadata)
        $apiCallsSavedPercent = 100; // 100% reduction (2 → 0 API calls)
        
        // Determine processing time color (optimization: <100ms is good, <1s is acceptable)
        $processingTimeColor = match(true) {
            $avgProcessingTimeMs === 0 => 'gray',
            $avgProcessingTimeMs < 100 => 'success', // Excellent (optimization target)
            $avgProcessingTimeMs < 1000 => 'warning', // Acceptable
            default => 'danger' // Too slow
        };
        
        return [
            Stat::make('Webhook Status', $statusText)
                ->description($description)
                ->descriptionIcon('heroicon-o-signal')
                ->color($statusColor),
                
            Stat::make('Webhooks (24h)', number_format($last24h))
                ->description("Success: {$processed} | Failed: {$failed}")
                ->descriptionIcon('heroicon-o-check-circle')
                ->color($failed > 0 ? 'warning' : 'success')
                ->chart(array_fill(0, 7, $last24h / 7)),
                
            Stat::make('Success Rate', "{$successRate}%")
                ->description($last24h > 0 ? "Out of {$last24h} webhooks" : "No webhooks received")
                ->descriptionIcon('heroicon-o-chart-bar')
                ->color($successRate >= 95 ? 'success' : ($successRate >= 80 ? 'warning' : 'danger')),
                
            Stat::make('Avg Processing Time', $avgProcessingTimeMs > 0 ? "{$avgProcessingTimeMs}ms" : 'N/A')
                ->description($avgProcessingTimeMs > 0 ? ($avgProcessingTimeMs < 100 ? "⚡ Optimized (<100ms)" : "{$avgProcessingTimeSeconds}s per webhook") : "No processing time data")
                ->descriptionIcon('heroicon-o-clock')
                ->color($processingTimeColor),
                
            Stat::make('API Calls Saved (24h)', number_format($apiCallsSaved))
                ->description("100% reduction (2 → 0 API calls per webhook)")
                ->descriptionIcon('heroicon-o-bolt')
                ->color('success'),
        ];
    }
}


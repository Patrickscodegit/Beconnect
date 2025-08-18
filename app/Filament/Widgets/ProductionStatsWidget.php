<?php

namespace App\Filament\Widgets;

use App\Models\Document;
use App\Models\Intake;
use App\Models\Extraction;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProductionStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;
    
    protected function getStats(): array
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $thisWeek = Carbon::now()->startOfWeek();
        $lastWeek = Carbon::now()->subWeek()->startOfWeek();

        // Today's stats
        $todayIntakes = Intake::whereDate('created_at', $today)->count();
        $yesterdayIntakes = Intake::whereDate('created_at', $yesterday)->count();
        $intakeChange = $yesterdayIntakes > 0 ? (($todayIntakes - $yesterdayIntakes) / $yesterdayIntakes) * 100 : 0;

        $todayDocuments = Document::whereDate('created_at', $today)->count();
        $yesterdayDocuments = Document::whereDate('created_at', $yesterday)->count();
        $documentChange = $yesterdayDocuments > 0 ? (($todayDocuments - $yesterdayDocuments) / $yesterdayDocuments) * 100 : 0;

        $todayExtractions = Extraction::whereDate('created_at', $today)->count();
        $yesterdayExtractions = Extraction::whereDate('created_at', $yesterday)->count();
        $extractionChange = $yesterdayExtractions > 0 ? (($todayExtractions - $yesterdayExtractions) / $yesterdayExtractions) * 100 : 0;

        // Processing stats
        $pendingIntakes = Intake::where('status', 'pending')->count();
        $processingIntakes = Intake::where('status', 'processing')->count();
        $failedIntakes = Intake::where('status', 'failed')->count();

        // Success rate
        $totalCompleted = Intake::where('status', 'completed')->count();
        $totalIntakes = Intake::count();
        $successRate = $totalIntakes > 0 ? ($totalCompleted / $totalIntakes) * 100 : 0;

        // Average confidence
        $avgConfidence = Extraction::avg('confidence');
        $avgConfidencePercent = $avgConfidence ? $avgConfidence * 100 : 0;

        return [
            Stat::make('Today\'s Intakes', $todayIntakes)
                ->description(sprintf('%.1f%% from yesterday', $intakeChange))
                ->descriptionIcon($intakeChange >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($intakeChange >= 0 ? 'success' : 'danger')
                ->chart([7, 12, 8, 15, 10, 18, $todayIntakes]),

            Stat::make('Documents Processed', $todayDocuments)
                ->description(sprintf('%.1f%% from yesterday', $documentChange))
                ->descriptionIcon($documentChange >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($documentChange >= 0 ? 'success' : 'danger')
                ->chart([5, 8, 12, 10, 15, 20, $todayDocuments]),

            Stat::make('AI Extractions', $todayExtractions)
                ->description(sprintf('%.1f%% from yesterday', $extractionChange))
                ->descriptionIcon($extractionChange >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($extractionChange >= 0 ? 'success' : 'danger')
                ->chart([3, 6, 9, 8, 12, 16, $todayExtractions]),

            Stat::make('Success Rate', sprintf('%.1f%%', $successRate))
                ->description('Overall completion rate')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color($successRate >= 90 ? 'success' : ($successRate >= 70 ? 'warning' : 'danger')),

            Stat::make('Pending Queue', $pendingIntakes + $processingIntakes)
                ->description($failedIntakes > 0 ? "{$failedIntakes} failed" : 'All processing normally')
                ->descriptionIcon($failedIntakes > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($failedIntakes > 0 ? 'warning' : 'success'),

            Stat::make('AI Confidence', sprintf('%.1f%%', $avgConfidencePercent))
                ->description('Average extraction confidence')
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color($avgConfidencePercent >= 80 ? 'success' : ($avgConfidencePercent >= 60 ? 'warning' : 'danger')),
        ];
    }
}

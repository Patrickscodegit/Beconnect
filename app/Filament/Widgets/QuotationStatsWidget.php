<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\QuotationRequest;

class QuotationStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Quotations', QuotationRequest::count())
                ->description('All time')
                ->descriptionIcon('heroicon-m-document-text')
                ->chart([7, 12, 18, 15, 22, 20, 25])
                ->color('success'),
                
            Stat::make('Pending Review', QuotationRequest::where('status', 'pending')->count())
                ->description('Requires action')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
                
            Stat::make('This Month', QuotationRequest::whereMonth('created_at', now()->month)->count())
                ->description('New quotations')
                ->descriptionIcon('heroicon-m-calendar')
                ->color('primary'),
                
            Stat::make('Accepted Rate', $this->calculateAcceptanceRate() . '%')
                ->description('Last 30 days')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
        ];
    }
    
    private function calculateAcceptanceRate(): float
    {
        $total = QuotationRequest::where('created_at', '>=', now()->subDays(30))
            ->whereIn('status', ['accepted', 'rejected'])
            ->count();
            
        if ($total === 0) {
            return 0;
        }
        
        $accepted = QuotationRequest::where('created_at', '>=', now()->subDays(30))
            ->where('status', 'accepted')
            ->count();
            
        return round(($accepted / $total) * 100, 1);
    }
}


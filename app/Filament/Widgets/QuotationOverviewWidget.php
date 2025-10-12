<?php

namespace App\Filament\Widgets;

use App\Models\QuotationRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class QuotationOverviewWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $total = QuotationRequest::count();
        $pending = QuotationRequest::where('status', 'pending')->count();
        $processing = QuotationRequest::where('status', 'processing')->count();
        $quoted = QuotationRequest::where('status', 'quoted')->count();
        $accepted = QuotationRequest::where('status', 'accepted')->count();
        
        $thisMonth = QuotationRequest::whereMonth('created_at', now()->month)->count();
        $lastMonth = QuotationRequest::whereMonth('created_at', now()->subMonth()->month)->count();
        $monthTrend = $lastMonth > 0 ? (($thisMonth - $lastMonth) / $lastMonth) * 100 : 0;
        
        $conversionRate = $total > 0 ? ($accepted / $total) * 100 : 0;
        
        return [
            Stat::make('Total Quotations', $total)
                ->description("$thisMonth this month")
                ->descriptionIcon($monthTrend >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($monthTrend >= 0 ? 'success' : 'danger')
                ->chart($this->getMonthlyChart()),
                
            Stat::make('Pending Review', $pending + $processing)
                ->description("$pending new, $processing in progress")
                ->color('warning')
                ->descriptionIcon('heroicon-m-clock')
                ->url(route('filament.admin.resources.quotation-requests.index', ['tableFilters[status][values][0]' => 'pending'])),
                
            Stat::make('Conversion Rate', number_format($conversionRate, 1) . '%')
                ->description("$accepted accepted out of $total total")
                ->descriptionIcon('heroicon-m-check-badge')
                ->color($conversionRate >= 50 ? 'success' : ($conversionRate >= 25 ? 'warning' : 'danger')),
        ];
    }
    
    /**
     * Get monthly quotation chart data for last 7 months
     */
    protected function getMonthlyChart(): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $count = QuotationRequest::whereYear('created_at', $date->year)
                ->whereMonth('created_at', $date->month)
                ->count();
            $data[] = $count;
        }
        return $data;
    }
}


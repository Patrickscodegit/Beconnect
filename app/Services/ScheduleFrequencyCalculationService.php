<?php

namespace App\Services;

use App\Models\ShippingSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ScheduleFrequencyCalculationService
{
    /**
     * Calculate dynamic frequency for a specific route
     */
    public function calculateRouteFrequency(string $carrierCode, string $polCode, string $podCode, int $monthsAhead = 6): array
    {
        $schedules = $this->getSchedulesForRoute($carrierCode, $polCode, $podCode, $monthsAhead);
        
        if ($schedules->isEmpty()) {
            return $this->getDefaultFrequency();
        }

        $monthlyCounts = $this->groupSchedulesByMonth($schedules);
        $frequencyData = $this->calculateFrequencyMetrics($monthlyCounts);

        return $frequencyData;
    }

    /**
     * Calculate frequency for a specific schedule
     */
    public function calculateScheduleFrequency(ShippingSchedule $schedule, int $monthsAhead = 6): array
    {
        return $this->calculateRouteFrequency(
            $schedule->carrier->code,
            $schedule->polPort->code,
            $schedule->podPort->code,
            $monthsAhead
        );
    }

    /**
     * Get all schedules for a specific route within the time window
     */
    private function getSchedulesForRoute(string $carrierCode, string $polCode, string $podCode, int $monthsAhead): Collection
    {
        // Include past schedules (3 months back) to get accurate frequency calculation
        $startDate = now()->subMonths(3);
        $endDate = now()->addMonths($monthsAhead);

        return ShippingSchedule::whereHas('carrier', function ($query) use ($carrierCode) {
                $query->where('code', $carrierCode);
            })
            ->whereHas('polPort', function ($query) use ($polCode) {
                $query->where('code', $polCode);
            })
            ->whereHas('podPort', function ($query) use ($podCode) {
                $query->where('code', $podCode);
            })
            ->whereBetween('ets_pol', [$startDate, $endDate])
            ->where('is_active', true)
            ->orderBy('ets_pol')
            ->get();
    }

    /**
     * Group schedules by month
     */
    private function groupSchedulesByMonth(Collection $schedules): array
    {
        $monthlyCounts = [];

        foreach ($schedules as $schedule) {
            $ets = Carbon::parse($schedule->ets_pol);
            $monthKey = $ets->format('Y-m');
            $monthName = $ets->format('M Y');

            if (!isset($monthlyCounts[$monthKey])) {
                $monthlyCounts[$monthKey] = [
                    'count' => 0,
                    'name' => $monthName,
                    'year' => $ets->year,
                    'month' => $ets->month
                ];
            }
            $monthlyCounts[$monthKey]['count']++;
        }

        return $monthlyCounts;
    }

    /**
     * Calculate frequency metrics from monthly counts
     */
    private function calculateFrequencyMetrics(array $monthlyCounts): array
    {
        if (empty($monthlyCounts)) {
            return $this->getDefaultFrequency();
        }

        $counts = array_column($monthlyCounts, 'count');
        $totalSailings = array_sum($counts);
        $totalMonths = count($monthlyCounts);
        
        // Calculate average frequency per month
        $averagePerMonth = round($totalSailings / $totalMonths, 1);
        
        // Find most common frequency pattern
        $frequencyPattern = $this->determineFrequencyPattern($counts);
        
        // Calculate weekly frequency (approximate)
        $weeklyFrequency = round($averagePerMonth / 4.33, 1); // 4.33 weeks per month average

        return [
            'frequency_per_month' => $averagePerMonth,
            'frequency_per_week' => $weeklyFrequency,
            'total_sailings' => $totalSailings,
            'total_months' => $totalMonths,
            'pattern' => $frequencyPattern,
            'monthly_breakdown' => $monthlyCounts,
            'is_dynamic' => true,
            'calculated_at' => now()->toISOString()
        ];
    }

    /**
     * Determine the frequency pattern (weekly, bi-weekly, monthly, etc.)
     */
    private function determineFrequencyPattern(array $counts): string
    {
        if (empty($counts)) {
            return 'unknown';
        }

        $average = array_sum($counts) / count($counts);
        $variance = array_sum(array_map(function($x) use ($average) {
            return pow($x - $average, 2);
        }, $counts)) / count($counts);

        // If variance is low, it's a consistent pattern
        if ($variance <= 0.5) {
            if ($average >= 4) return 'weekly';
            if ($average >= 2) return 'bi-weekly';
            if ($average >= 1) return 'monthly';
            return 'irregular';
        }

        // High variance means irregular schedule
        return 'irregular';
    }

    /**
     * Get default frequency when no data is available
     */
    private function getDefaultFrequency(): array
    {
        return [
            'frequency_per_month' => 1.0,
            'frequency_per_week' => 0.25,
            'total_sailings' => 0,
            'total_months' => 0,
            'pattern' => 'unknown',
            'monthly_breakdown' => [],
            'is_dynamic' => false,
            'calculated_at' => now()->toISOString()
        ];
    }

    /**
     * Get frequency display text for UI
     */
    public function getFrequencyDisplayText(array $frequencyData): string
    {
        $monthlyFreq = $frequencyData['frequency_per_month'];
        $pattern = $frequencyData['pattern'];
        $totalSailings = $frequencyData['total_sailings'];

        // If we have actual data, show it
        if ($frequencyData['is_dynamic'] && $totalSailings > 0) {
            return $this->formatFrequencyDisplay($monthlyFreq, $pattern);
        }

        // Fallback to default
        return "Monthly service";
    }

    /**
     * Format frequency display using service pattern terminology
     */
    private function formatFrequencyDisplay(float $monthlyFreq, string $pattern): string
    {
        // Service pattern based display - most user-friendly
        if ($monthlyFreq >= 4.0) {
            return "Weekly service";
        } elseif ($monthlyFreq >= 2.5) {
            return "2-3x/month";
        } elseif ($monthlyFreq >= 1.5) {
            return "Bi-weekly service";
        } elseif ($monthlyFreq >= 0.8) {
            return "Monthly service";
        } elseif ($monthlyFreq >= 0.5) {
            return "~1x/month";
        } else {
            return "Irregular service";
        }
    }

    /**
     * Bulk calculate frequencies for multiple routes
     */
    public function calculateBulkFrequencies(array $routes, int $monthsAhead = 6): array
    {
        $results = [];

        foreach ($routes as $route) {
            $carrierCode = $route['carrier_code'];
            $polCode = $route['pol_code'];
            $podCode = $route['pod_code'];

            $results["{$carrierCode}-{$polCode}-{$podCode}"] = $this->calculateRouteFrequency(
                $carrierCode, $polCode, $podCode, $monthsAhead
            );
        }

        return $results;
    }
}

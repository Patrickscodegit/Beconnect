<?php

namespace App\Services;

class UnitConverter
{
    /**
     * Convert metric measurements to USA units
     */
    public function metricToUsa(
        float $lengthM,
        float $widthM,
        float $heightM,
        int $weightKg
    ): array {
        return [
            'length_in' => round($lengthM * 39.3701, 2),
            'width_in' => round($widthM * 39.3701, 2),
            'height_in' => round($heightM * 39.3701, 2),
            'weight_lb' => round($weightKg * 2.20462, 0),
        ];
    }

    /**
     * Convert USA measurements to metric units
     */
    public function usaToMetric(
        float $lengthIn,
        float $widthIn,
        float $heightIn,
        int $weightLb
    ): array {
        return [
            'length_m' => round($lengthIn * 0.0254, 2),
            'width_m' => round($widthIn * 0.0254, 2),
            'height_m' => round($heightIn * 0.0254, 2),
            'weight_kg' => round($weightLb * 0.453592, 0),
        ];
    }

    /**
     * Convert meters to feet and inches
     */
    public function metersToFeetInches(float $meters): array
    {
        $totalInches = $meters * 39.3701;
        $feet = floor($totalInches / 12);
        $inches = round($totalInches % 12, 1);
        
        return [
            'feet' => (int) $feet,
            'inches' => $inches,
            'display' => $feet . "' " . $inches . '"',
        ];
    }

    /**
     * Convert feet and inches to meters
     */
    public function feetInchesToMeters(int $feet, float $inches): float
    {
        $totalInches = ($feet * 12) + $inches;
        return round($totalInches * 0.0254, 2);
    }
}

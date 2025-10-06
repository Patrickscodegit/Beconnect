<?php

namespace App\Services\ScheduleExtraction;

interface ScheduleExtractionStrategyInterface
{
    /**
     * Extract schedules for a given POL/POD combination
     */
    public function extractSchedules(string $pol, string $pod): array;

    /**
     * Get the carrier code this strategy handles
     */
    public function getCarrierCode(): string;

    /**
     * Get the update frequency for this carrier
     */
    public function getUpdateFrequency(): string;

    /**
     * Get the last update timestamp
     */
    public function getLastUpdate(): ?\DateTime;

    /**
     * Check if this strategy supports the given POL/POD
     */
    public function supports(string $pol, string $pod): bool;
}



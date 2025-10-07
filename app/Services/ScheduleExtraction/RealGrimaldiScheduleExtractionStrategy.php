<?php

namespace App\Services\ScheduleExtraction;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Port;

class RealGrimaldiScheduleExtractionStrategy extends RealDataExtractionStrategy
{
    public function __construct()
    {
        parent::__construct('GRIMALDI', 'Grimaldi Lines', 'https://www.gnet.grimaldi-eservice.com');
    }

    protected function fetchRealSchedules(string $polCode, string $podCode): array
    {
        try {
            // Make actual HTTP request to Grimaldi GNET system
            $response = Http::timeout(30)->get('https://www.gnet.grimaldi-eservice.com/GNET/Pages_ScheduleInfo/WFSchedule', [
                'pol' => $polCode,
                'pod' => $podCode
            ]);

            if ($response->successful()) {
                $content = $response->body();
                
                // Check if route actually exists
                if (!$this->checkRouteExistsInContent($content, $polCode, $podCode)) {
                    Log::info("Grimaldi: Route {$polCode}->{$podCode} does not exist on GNET");
                    return [];
                }
                
                return $this->parseGrimaldiWebsiteContent($content, $polCode, $podCode);
            }
            
        } catch (\Exception $e) {
            Log::error("Grimaldi: Failed to fetch real data for {$polCode}->{$podCode}: " . $e->getMessage());
        }
        
        return [];
    }

    protected function checkRouteExistsInContent(string $content, string $polCode, string $podCode): bool
    {
        // Check for Grimaldi-specific "no voyages found" message
        $notFoundIndicators = [
            'No voyages found',
            'No schedules found',
            'No results found',
            'No data available'
        ];
        
        foreach ($notFoundIndicators as $indicator) {
            if (stripos($content, $indicator) !== false) {
                return false;
            }
        }
        
        // Check for actual schedule data indicators
        $scheduleIndicators = [
            'vessel',
            'departure',
            'arrival',
            'transit',
            'frequency',
            'service'
        ];
        
        $foundIndicators = 0;
        foreach ($scheduleIndicators as $indicator) {
            if (stripos($content, $indicator) !== false) {
                $foundIndicators++;
            }
        }
        
        // If we find schedule indicators, route likely exists
        return $foundIndicators >= 2;
    }

    protected function parseGrimaldiWebsiteContent(string $content, string $polCode, string $podCode): array
    {
        $schedules = [];
        
        // This would parse the actual Grimaldi GNET website content
        // For now, return empty array to ensure no mock data
        
        Log::info("Grimaldi: Real data parsing not fully implemented yet for {$polCode}->{$podCode}");
        
        return $schedules;
    }

    public function supports(string $polCode, string $podCode): bool
    {
        // Only support the 3 required POLs: Antwerp, Zeebrugge, Flushing
        $supportedPols = ['ANR', 'ZEE', 'FLU'];
        
        if (!in_array($polCode, $supportedPols)) {
            return false;
        }
        
        // Only return true if we can verify the route exists on Grimaldi GNET
        return $this->validateRouteExists($polCode, $podCode);
    }

    protected function validateRouteExists(string $polCode, string $podCode): bool
    {
        try {
            // Make actual HTTP request to Grimaldi GNET to verify route exists
            $response = Http::timeout(30)->get('https://www.gnet.grimaldi-eservice.com/GNET/Pages_ScheduleInfo/WFSchedule', [
                'pol' => $polCode,
                'pod' => $podCode
            ]);

            if ($response->successful()) {
                $content = $response->body();
                return $this->checkRouteExistsInContent($content, $polCode, $podCode);
            }
            
        } catch (\Exception $e) {
            Log::error("Grimaldi: Route validation failed for {$polCode}->{$podCode}: " . $e->getMessage());
        }
        
        return false;
    }
}



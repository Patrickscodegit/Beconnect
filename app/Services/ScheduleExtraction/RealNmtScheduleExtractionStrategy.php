<?php

namespace App\Services\ScheduleExtraction;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Port;

class RealNmtScheduleExtractionStrategy extends RealDataExtractionStrategy
{
    public function __construct()
    {
        parent::__construct('NMT', 'NMT Shipping', 'https://www.nmtshipping.com');
    }

    protected function fetchRealSchedules(string $polCode, string $podCode): array
    {
        try {
            // Make actual HTTP request to NMT website
            $response = Http::timeout(30)->get('https://www.nmtshipping.com/schedules', [
                'pol' => $polCode,
                'pod' => $podCode
            ]);

            if ($response->successful()) {
                $content = $response->body();
                
                // Check if route actually exists
                if (!$this->checkRouteExistsInContent($content, $polCode, $podCode)) {
                    Log::info("NMT Shipping: Route {$polCode}->{$podCode} does not exist on website");
                    return [];
                }
                
                return $this->parseNmtWebsiteContent($content, $polCode, $podCode);
            }
            
        } catch (\Exception $e) {
            Log::error("NMT Shipping: Failed to fetch real data for {$polCode}->{$podCode}: " . $e->getMessage());
        }
        
        return [];
    }

    protected function checkRouteExistsInContent(string $content, string $polCode, string $podCode): bool
    {
        // Check for NMT-specific "no voyages found" message
        $notFoundIndicators = [
            'No voyages found',
            'No schedules found',
            'No results found'
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
            'frequency'
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

    protected function parseNmtWebsiteContent(string $content, string $polCode, string $podCode): array
    {
        $schedules = [];
        
        // This would parse the actual NMT website content
        // For now, return empty array to ensure no mock data
        
        Log::info("NMT Shipping: Real data parsing not fully implemented yet for {$polCode}->{$podCode}");
        
        return $schedules;
    }

    public function supports(string $polCode, string $podCode): bool
    {
        // Only support the 3 required POLs: Antwerp, Zeebrugge, Flushing
        $supportedPols = ['ANR', 'ZEE', 'FLU'];
        
        if (!in_array($polCode, $supportedPols)) {
            return false;
        }
        
        // Only return true if we can verify the route exists on NMT website
        return $this->validateRouteExists($polCode, $podCode);
    }

    protected function validateRouteExists(string $polCode, string $podCode): bool
    {
        try {
            // Make actual HTTP request to NMT website to verify route exists
            $response = Http::timeout(30)->get('https://www.nmtshipping.com/schedules', [
                'pol' => $polCode,
                'pod' => $podCode
            ]);

            if ($response->successful()) {
                $content = $response->body();
                return $this->checkRouteExistsInContent($content, $polCode, $podCode);
            }
            
        } catch (\Exception $e) {
            Log::error("NMT Shipping: Route validation failed for {$polCode}->{$podCode}: " . $e->getMessage());
        }
        
        return false;
    }
}



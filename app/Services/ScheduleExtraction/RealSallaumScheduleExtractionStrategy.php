<?php

namespace App\Services\ScheduleExtraction;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Port;

class RealSallaumScheduleExtractionStrategy extends RealDataExtractionStrategy
{
    public function __construct()
    {
        parent::__construct(
            'SALLAUM',
            'Sallaum Lines', 
            'https://sallaumlines.com'
        );
    }

    protected function fetchRealSchedules(string $polCode, string $podCode): array
    {
        try {
            // Attempt to fetch real schedule data from Sallaum Lines website
            // Using their route finder: https://sallaumlines.com/route-finder/
            
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ])
                ->get('https://sallaumlines.com/route-finder/', [
                    'origin' => $polCode,
                    'destination' => $podCode
                ]);

            if ($response->successful()) {
                $content = $response->body();
                
                // Check if route exists
                if (!$this->checkRouteExistsInContent($content, $polCode, $podCode)) {
                    Log::info("Sallaum Lines: Route {$polCode}->{$podCode} does not exist on website");
                    return [];
                }
                
                return $this->parseSallaumHtml($content, $polCode, $podCode);
            }
            
        } catch (\Exception $e) {
            Log::error("Sallaum Lines: Failed to fetch real data for {$polCode}->{$podCode}: " . $e->getMessage());
        }
        
        return [];
    }

    protected function parseSallaumHtml(string $html, string $polCode, string $podCode): array
    {
        $schedules = [];
        
        // Parse the HTML to extract real schedule information
        // This will extract: service name, frequency, transit time, vessel, ETS, ETA
        
        try {
            // Look for schedule data in the HTML
            // Sallaum typically shows:
            // - Service routes
            // - Frequency (weekly/monthly)
            // - Transit times
            // - Next sailing dates
            
            // Pattern to find schedule tables or divs
            if (preg_match_all('/<div[^>]*class="[^"]*schedule[^"]*"[^>]*>(.*?)<\/div>/is', $html, $matches)) {
                foreach ($matches[1] as $scheduleBlock) {
                    $schedule = $this->extractScheduleFromBlock($scheduleBlock, $polCode, $podCode);
                    if ($schedule) {
                        $schedules[] = $schedule;
                    }
                }
            }
            
            // If no schedules found in structured format, look for schedule information in text
            if (empty($schedules)) {
                $schedules = $this->extractScheduleFromText($html, $polCode, $podCode);
            }
            
        } catch (\Exception $e) {
            Log::error("Sallaum Lines: Failed to parse HTML for {$polCode}->{$podCode}: " . $e->getMessage());
        }
        
        return $schedules;
    }

    protected function extractScheduleFromBlock(string $block, string $polCode, string $podCode): ?array
    {
        // Extract schedule details from a schedule block
        $schedule = [];
        
        // Extract service name
        if (preg_match('/service[:\s]+([^<\n]+)/i', $block, $match)) {
            $schedule['service_name'] = trim($match[1]);
        }
        
        // Extract frequency
        if (preg_match('/frequency[:\s]+([^<\n]+)/i', $block, $match)) {
            $schedule['frequency'] = trim($match[1]);
        } elseif (preg_match('/(weekly|monthly|daily)/i', $block, $match)) {
            $schedule['frequency'] = ucfirst(strtolower($match[1]));
        }
        
        // Extract transit time
        if (preg_match('/transit[:\s]+(\d+)\s*(days?|hours?)/i', $block, $match)) {
            $schedule['transit_time_days'] = (int)$match[1];
        }
        
        // If we have at least service name or frequency, consider it valid
        if (!empty($schedule['service_name']) || !empty($schedule['frequency'])) {
            return array_merge($schedule, [
                'pol_code' => $polCode,
                'pod_code' => $podCode,
                'carrier_code' => 'SALLAUM',
                'service_type' => 'RORO',
                'data_source' => 'website_scrape'
            ]);
        }
        
        return null;
    }

    protected function extractScheduleFromText(string $html, string $polCode, string $podCode): array
    {
        // Fallback: extract schedule information from text content
        $schedules = [];
        
        // Strip HTML tags to analyze text
        $text = strip_tags($html);
        
        // Look for common schedule patterns
        $patterns = [
            '/([A-Z][a-z\s]+)\s+service.*?(\d+)\s+days?/i',
            '/frequency[:\s]+(weekly|monthly|daily)/i',
            '/sailing.*?every\s+(\d+)\s+(week|day|month)/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                $schedules[] = [
                    'pol_code' => $polCode,
                    'pod_code' => $podCode,
                    'carrier_code' => 'SALLAUM',
                    'service_type' => 'RORO',
                    'frequency' => isset($match[1]) ? trim($match[1]) : 'Unknown',
                    'transit_time_days' => null,
                    'data_source' => 'website_text'
                ];
                break; // Take first match
            }
        }
        
        return $schedules;
    }

    protected function parseRealSchedules(array $realData, string $polCode, string $podCode): array
    {
        // Real data is already parsed in fetchRealSchedules
        return $realData;
    }

    protected function checkRouteExistsInContent(string $content, string $polCode, string $podCode): bool
    {
        // Sallaum-specific "no route" indicators
        $notFoundIndicators = [
            'No routes found',
            'No services available',
            'Route not available',
            'No sailings found',
            'Sorry, we don\'t operate this route'
        ];
        
        foreach ($notFoundIndicators as $indicator) {
            if (stripos($content, $indicator) !== false) {
                return false;
            }
        }
        
        // Check for positive indicators
        $routeIndicators = [
            'schedule',
            'sailing',
            'departure',
            'arrival',
            'frequency',
            'service',
            'route details'
        ];
        
        $foundIndicators = 0;
        foreach ($routeIndicators as $indicator) {
            if (stripos($content, $indicator) !== false) {
                $foundIndicators++;
            }
        }
        
        // If we find at least 2 route indicators, route likely exists
        return $foundIndicators >= 2;
    }

    public function supports(string $polCode, string $podCode): bool
    {
        // Only support the 3 required POLs: Antwerp, Zeebrugge, Flushing
        $supportedPols = ['ANR', 'ZEE', 'FLU'];
        
        if (!in_array($polCode, $supportedPols)) {
            return false;
        }
        
        // Verify the route exists on Sallaum's website
        return $this->validateRouteExists($polCode, $podCode);
    }

    protected function validateRouteExists(string $polCode, string $podCode): bool
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])
                ->get('https://sallaumlines.com/route-finder/', [
                    'origin' => $polCode,
                    'destination' => $podCode
                ]);

            if ($response->successful()) {
                $content = $response->body();
                $exists = $this->checkRouteExistsInContent($content, $polCode, $podCode);
                
                Log::info("Sallaum Lines: Route validation for {$polCode}->{$podCode}: " . ($exists ? 'EXISTS' : 'NOT FOUND'));
                
                return $exists;
            }
            
        } catch (\Exception $e) {
            Log::error("Sallaum Lines: Route validation failed for {$polCode}->{$podCode}: " . $e->getMessage());
        }
        
        return false;
    }
}


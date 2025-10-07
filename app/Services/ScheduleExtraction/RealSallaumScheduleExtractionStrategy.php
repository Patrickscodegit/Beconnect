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
            // Fetch real schedule data from Sallaum Lines' Europe to Africa schedule page
            // Source: https://sallaumlines.com/schedules/europe-to-west-and-south-africa/
            
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ])
                ->get('https://sallaumlines.com/schedules/europe-to-west-and-south-africa/');

            if ($response->successful()) {
                $html = $response->body();
                
                Log::info("Sallaum Lines: Fetched schedule page, parsing for {$polCode}->{$podCode}");
                
                return $this->parseSallaumScheduleTable($html, $polCode, $podCode);
            }
            
        } catch (\Exception $e) {
            Log::error("Sallaum Lines: Failed to fetch real data for {$polCode}->{$podCode}: " . $e->getMessage());
        }
        
        return [];
    }

    protected function parseSallaumScheduleTable(string $html, string $polCode, string $podCode): array
    {
        $schedules = [];
        
        try {
            // Map port codes to Sallaum's port names
            $portNameMapping = [
                'ANR' => 'Antwerp',
                'ZEE' => 'Zeebrugge',
                'FLU' => 'Amsterdam', // Flushing is close to Amsterdam
                'LOS' => 'Lagos',
                'DKR' => 'Dakar',
                'ABJ' => 'Abidjan',
                'TEM' => 'Tema', // Not on this route
                'CKY' => 'Conakry',
                'LFW' => 'Lome',
                'COO' => 'Cotonou',
            ];
            
            $polName = $portNameMapping[$polCode] ?? $polCode;
            $podName = $portNameMapping[$podCode] ?? $podCode;
            
            // Extract table content
            if (preg_match('/<table[^>]*>(.*?)<\/table>/is', $html, $tableMatch)) {
                $tableHtml = $tableMatch[1];
                
                // Extract vessel names from header row
                preg_match_all('/<th[^>]*>([^<]+)<\/th>/i', $tableHtml, $vesselMatches);
                $vessels = $vesselMatches[1];
                
                // Remove empty vessels and clean up
                $vessels = array_filter($vessels, function($v) {
                    return !empty(trim($v)) && strlen(trim($v)) > 2;
                });
                
                // Extract rows
                preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tableHtml, $rowMatches);
                
                $polDates = [];
                $podDates = [];
                
                foreach ($rowMatches[1] as $rowHtml) {
                    // Check if this row contains our POL
                    if (stripos($rowHtml, $polName) !== false) {
                        preg_match_all('/<td[^>]*>([^<]*)<\/td>/i', $rowHtml, $cellMatches);
                        $polDates = $cellMatches[1];
                    }
                    
                    // Check if this row contains our POD
                    if (stripos($rowHtml, $podName) !== false) {
                        preg_match_all('/<td[^>]*>([^<]*)<\/td>/i', $rowHtml, $cellMatches);
                        $podDates = $cellMatches[1];
                    }
                }
                
                // Match POL and POD dates for each vessel
                foreach ($vessels as $index => $vessel) {
                    $vesselName = trim($vessel);
                    $polDate = isset($polDates[$index]) ? trim(strip_tags($polDates[$index])) : null;
                    $podDate = isset($podDates[$index]) ? trim(strip_tags($podDates[$index])) : null;
                    
                    // Only create schedule if both POL and POD dates exist
                    if (!empty($polDate) && !empty($podDate) && $polDate !== 'ETA') {
                        $schedules[] = [
                            'pol_code' => $polCode,
                            'pod_code' => $podCode,
                            'carrier_code' => 'SALLAUM',
                            'carrier_name' => 'Sallaum Lines',
                            'service_type' => 'RORO',
                            'service_name' => "Europe to Africa - {$vesselName}",
                            'vessel_name' => $vesselName,
                            'ets_pol' => $this->parseDate($polDate),
                            'eta_pod' => $this->parseDate($podDate),
                            'frequency' => 'As per schedule',
                            'data_source' => 'website_table',
                            'source_url' => 'https://sallaumlines.com/schedules/europe-to-west-and-south-africa/'
                        ];
                    }
                }
            }
            
            Log::info("Sallaum Lines: Extracted " . count($schedules) . " schedules for {$polCode}->{$podCode}");
            
        } catch (\Exception $e) {
            Log::error("Sallaum Lines: Failed to parse schedule table for {$polCode}->{$podCode}: " . $e->getMessage());
        }
        
        return $schedules;
    }
    
    protected function parseDate(string $dateStr): ?string
    {
        // Parse dates like "2 September 2025" or "21 September 2025"
        try {
            // Remove bold markers and extra spaces
            $dateStr = preg_replace('/<[^>]+>/', '', $dateStr);
            $dateStr = preg_replace('/\s+/', ' ', trim($dateStr));
            
            if (empty($dateStr) || $dateStr === 'ETA') {
                return null;
            }
            
            $date = \DateTime::createFromFormat('d F Y', $dateStr);
            if ($date) {
                return $date->format('Y-m-d');
            }
            
            // Try alternative format
            $date = \DateTime::createFromFormat('j F Y', $dateStr);
            if ($date) {
                return $date->format('Y-m-d');
            }
            
        } catch (\Exception $e) {
            Log::warning("Sallaum Lines: Could not parse date: {$dateStr}");
        }
        
        return null;
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
        // Map port codes to Sallaum's port names
        $portNameMapping = [
            'ANR' => 'Antwerp',
            'ZEE' => 'Zeebrugge',
            'FLU' => 'Amsterdam',
            'LOS' => 'Lagos',
            'DKR' => 'Dakar',
            'ABJ' => 'Abidjan',
            'CKY' => 'Conakry',
            'LFW' => 'Lome',
            'COO' => 'Cotonou',
        ];
        
        $polName = $portNameMapping[$polCode] ?? $polCode;
        $podName = $portNameMapping[$podCode] ?? $podCode;
        
        // Check if both POL and POD appear in the schedule table
        $hasPol = stripos($content, $polName) !== false;
        $hasPod = stripos($content, $podName) !== false;
        $hasTable = stripos($content, '<table') !== false;
        
        return $hasPol && $hasPod && $hasTable;
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
            // Fetch the actual schedule page to validate
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])
                ->get('https://sallaumlines.com/schedules/europe-to-west-and-south-africa/');

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


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
                'TEM' => 'Tema',
                'CKY' => 'Conakry',
                'LFW' => 'Lome',
                'COO' => 'Cotonou',
            ];
            
            $polName = $portNameMapping[$polCode] ?? $polCode;
            $podName = $portNameMapping[$podCode] ?? $podCode;
            
            // Extract table content
            if (preg_match('/<table[^>]*>(.*?)<\/table>/is', $html, $tableMatch)) {
                $tableHtml = $tableMatch[1];
                
                // Extract vessel names from first row (header with vessel names)
                preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tableHtml, $allRows);
                
                $vessels = [];
                $voyageNumbers = [];
                
                if (isset($allRows[1][0])) {
                    // First row contains vessel names
                    preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $allRows[1][0], $vesselCells);
                    foreach ($vesselCells[1] as $cell) {
                        $vesselName = trim(strip_tags($cell));
                        if (!empty($vesselName) && $vesselName !== 'ETA') {
                            $vessels[] = $vesselName;
                        }
                    }
                }
                
                if (isset($allRows[1][1])) {
                    // Second row contains voyage numbers
                    preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $allRows[1][1], $voyageCells);
                    foreach ($voyageCells[1] as $cell) {
                        $voyageNo = trim(strip_tags($cell));
                        if (!empty($voyageNo) && $voyageNo !== 'ETA') {
                            $voyageNumbers[] = $voyageNo;
                        }
                    }
                }
                
                Log::info("Sallaum Lines: Found " . count($vessels) . " vessels", ['vessels' => $vessels]);
                
                // Find POL and POD rows
                $polDates = [];
                $podDates = [];
                
                foreach ($allRows[1] as $rowHtml) {
                    // Check if this row contains our POL
                    if (stripos($rowHtml, $polName) !== false) {
                        // Extract ALL content from cells (including nested spans/divs)
                        preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $rowHtml, $cells);
                        foreach ($cells[1] as $cellContent) {
                            $dateText = $this->extractDateFromCell($cellContent);
                            $polDates[] = $dateText;
                        }
                        Log::info("Sallaum Lines: Found POL row for {$polName}", ['dates_count' => count($polDates)]);
                    }
                    
                    // Check if this row contains our POD
                    if (stripos($rowHtml, $podName) !== false) {
                        preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $rowHtml, $cells);
                        foreach ($cells[1] as $cellContent) {
                            $dateText = $this->extractDateFromCell($cellContent);
                            $podDates[] = $dateText;
                        }
                        Log::info("Sallaum Lines: Found POD row for {$podName}", ['dates_count' => count($podDates)]);
                    }
                }
                
                // Match POL and POD dates for each vessel
                $vesselCount = count($vessels);
                for ($i = 0; $i < $vesselCount; $i++) {
                    $vesselName = $vessels[$i] ?? null;
                    $voyageNo = $voyageNumbers[$i] ?? null;
                    // Skip first cell (port name cell) when accessing dates
                    $polDate = $polDates[$i + 1] ?? null;
                    $podDate = $podDates[$i + 1] ?? null;
                    
                    if ($vesselName && $polDate && $podDate && !empty($polDate) && !empty($podDate)) {
                        $parsedPolDate = $this->parseDate($polDate);
                        $parsedPodDate = $this->parseDate($podDate);
                        
                        if ($parsedPolDate && $parsedPodDate) {
                            $schedules[] = [
                                'pol_code' => $polCode,
                                'pod_code' => $podCode,
                                'carrier_code' => 'SALLAUM',
                                'carrier_name' => 'Sallaum Lines',
                                'service_type' => 'RORO',
                                'service_name' => "Europe to Africa",
                                'vessel_name' => $vesselName,
                                'voyage_number' => $voyageNo,
                                'ets_pol' => $parsedPolDate,
                                'eta_pod' => $parsedPodDate,
                                'frequency' => 'Weekly',
                                'data_source' => 'website_table',
                                'source_url' => 'https://sallaumlines.com/schedules/europe-to-west-and-south-africa/'
                            ];
                            
                            Log::info("Sallaum Lines: Created schedule", [
                                'vessel' => $vesselName,
                                'pol_date' => $parsedPolDate,
                                'pod_date' => $parsedPodDate
                            ]);
                        }
                    }
                }
            }
            
            Log::info("Sallaum Lines: Extracted " . count($schedules) . " schedules for {$polCode}->{$podCode}");
            
        } catch (\Exception $e) {
            Log::error("Sallaum Lines: Failed to parse schedule table for {$polCode}->{$podCode}: " . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        return $schedules;
    }
    
    protected function extractDateFromCell(string $cellHtml): ?string
    {
        // Extract date from cell HTML, handling complex nested structure
        // Format: <strong>27</strong> August 2025 (split across spans)
        
        $day = null;
        $monthYear = null;
        
        // Extract day from <strong> tag
        if (preg_match('/<strong[^>]*>(\d+)<\/strong>/i', $cellHtml, $dayMatch)) {
            $day = $dayMatch[1];
        }
        
        // Extract month and year - look for month names
        $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        foreach ($months as $month) {
            if (stripos($cellHtml, $month) !== false) {
                // Extract year after month
                if (preg_match('/' . $month . '\s*(\d{4})/i', $cellHtml, $yearMatch)) {
                    $monthYear = $month . ' ' . $yearMatch[1];
                    break;
                }
            }
        }
        
        // Combine day and month/year
        if ($day && $monthYear) {
            return $day . ' ' . $monthYear;
        }
        
        // Fallback: try to extract full date directly
        $fullText = strip_tags($cellHtml);
        $fullText = preg_replace('/\s+/', ' ', trim($fullText));
        
        if (preg_match('/(\d+)\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})/i', $fullText, $match)) {
            return $match[1] . ' ' . $match[2] . ' ' . $match[3];
        }
        
        return null;
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


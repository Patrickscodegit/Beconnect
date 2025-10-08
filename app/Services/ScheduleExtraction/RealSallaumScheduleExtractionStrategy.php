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
            // Source: https://sallaumlines.com/schedules/europe-to-west-and-south-africa/
            $portNameMapping = [
                // POLs (Origins)
                'ANR' => 'Antwerp',
                'ZEE' => 'Zeebrugge',
                'FLU' => 'Amsterdam', // Flushing is close to Amsterdam on Sallaum's schedule
                
                // PODs (Destinations) - West Africa
                'ABJ' => 'Abidjan',
                'CKY' => 'Conakry',
                'COO' => 'Cotonou',
                'DKR' => 'Dakar',
                'DLA' => 'Douala',
                'LOS' => 'Lagos',
                'LFW' => 'Lome',
                'PNR' => 'Pointe Noire',
                
                // PODs (Destinations) - East Africa
                'DAR' => 'Dar es Salaam',
                'MBA' => 'Mombasa',
                
                // PODs (Destinations) - South Africa
                'DUR' => 'Durban',
                'ELS' => 'East London',
                'PLZ' => 'Port Elizabeth',
                'WVB' => 'Walvis Bay',
            ];
            
            $polName = $portNameMapping[$polCode] ?? $polCode;
            $podName = $portNameMapping[$podCode] ?? $podCode;
            
            // Parse the real HTML from Sallaum's website with VERTICAL column reading
            
            // Extract table content
            if (preg_match('/<table[^>]*>(.*?)<\/table>/is', $html, $tableMatch)) {
                $tableHtml = $tableMatch[1];
                
                // IMPORTANT: Preprocess HTML to handle self-closing <td> tags
                // The Sallaum website has malformed HTML with self-closing <td> tags for empty cells
                // Convert <td> or <td /> to <td></td> so DOMDocument can parse correctly
                $tableHtml = preg_replace('/<td([^>]*)>\s*(?=<td|<\/tr)/', '<td$1></td>', $tableHtml);
                
                // Extract all table rows
                preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tableHtml, $allRows);
                
                // Find vessel names and voyage numbers from the first two rows
                $vessels = [];
                $voyageNumbers = [];
                
                // First row: vessel names
                if (isset($allRows[1][0])) {
                    preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $allRows[1][0], $cells);
                    foreach ($cells[1] as $cell) {
                        $vesselName = trim(strip_tags($cell));
                        if (!empty($vesselName) && $vesselName !== 'ETA' && !preg_match('/\d{4}/', $vesselName)) {
                            $vessels[] = $vesselName;
                        }
                    }
                }
                
                // Second row: voyage numbers
                if (isset($allRows[1][1])) {
                    preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $allRows[1][1], $cells);
                    foreach ($cells[1] as $cell) {
                        $voyageNo = trim(strip_tags($cell));
                        // Updated pattern to match voyage numbers like 25PA09, 25OB03, etc.
                        if (preg_match('/^[0-9]{2}[A-Z]{2}[0-9]{2}$/', $voyageNo)) {
                            $voyageNumbers[] = $voyageNo;
                        }
                    }
                }
                
                Log::info("Sallaum Lines: Found " . count($vessels) . " vessels", ['vessels' => $vessels]);
                Log::info("Sallaum Lines: Found " . count($voyageNumbers) . " voyage numbers", ['voyages' => $voyageNumbers]);
                
                // Now read VERTICALLY - each vessel has its own column
                // Use DOMDocument to properly parse HTML including self-closing tags
                $polDatesByVoyage = [];
                $podDatesByVoyage = [];
                
                // Use DOMDocument for robust HTML parsing
                // Suppress libxml errors for malformed HTML
                libxml_use_internal_errors(true);
                
                $dom = new \DOMDocument();
                $dom->loadHTML($tableHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                
                // Clear any libxml errors
                libxml_clear_errors();
                libxml_use_internal_errors(false);
                
                $xpath = new \DOMXPath($dom);
                
                // Find POL row
                $polRows = $xpath->query("//tr[contains(., '{$polName}')]");
                foreach ($polRows as $row) {
                    $cells = $xpath->query(".//td[@headers]", $row);
                    foreach ($cells as $cell) {
                        $headers = $cell->getAttribute('headers');
                        
                        // Extract voyage code from headers attribute (e.g., "25PA09-date")
                        if (preg_match('/(\d{2}[A-Z]{2}\d{2})-date/', $headers, $voyageMatch)) {
                            $voyageCode = $voyageMatch[1];
                            
                            // Get cell content
                            $cellContent = $dom->saveHTML($cell);
                            $dateText = $this->extractDateFromCell($cellContent);
                            
                            if ($dateText) {
                                $polDatesByVoyage[$voyageCode] = $dateText;
                            }
                        }
                    }
                    Log::info("Sallaum Lines: Found POL row for {$polName}", ['dates_by_voyage' => $polDatesByVoyage]);
                    break; // Only process first matching row
                }
                
                // Find POD row
                $podRows = $xpath->query("//tr[contains(., '{$podName}')]");
                foreach ($podRows as $row) {
                    $cells = $xpath->query(".//td[@headers]", $row);
                    foreach ($cells as $cell) {
                        $headers = $cell->getAttribute('headers');
                        
                        // Extract voyage code from headers attribute (e.g., "25PA09-date")
                        if (preg_match('/(\d{2}[A-Z]{2}\d{2})-date/', $headers, $voyageMatch)) {
                            $voyageCode = $voyageMatch[1];
                            
                            // Get cell content
                            $cellContent = $dom->saveHTML($cell);
                            $dateText = $this->extractDateFromCell($cellContent);
                            
                            if ($dateText) {
                                $podDatesByVoyage[$voyageCode] = $dateText;
                            }
                        }
                    }
                    Log::info("Sallaum Lines: Found POD row for {$podName}", ['dates_by_voyage' => $podDatesByVoyage]);
                    break; // Only process first matching row
                }
                
                // Create schedules by matching vessels to their voyage numbers
                // Use the voyage number to match POL and POD dates from the headers attribute parsing
                
                foreach ($vessels as $vesselIndex => $vesselName) {
                    $voyageNo = $voyageNumbers[$vesselIndex] ?? null;
                    
                    if (!$voyageNo) {
                        Log::info("Sallaum Lines: Skipping vessel {$vesselName} - no voyage number found");
                        continue;
                    }
                    
                    // Match dates using voyage number from headers attribute
                    $polDate = $polDatesByVoyage[$voyageNo] ?? null;
                    $podDate = $podDatesByVoyage[$voyageNo] ?? null;
                    
                // Only create schedule if BOTH POL and POD dates exist for this voyage
                if ($polDate && $podDate) {
                    $parsedPolDate = $this->parseDate($polDate);
                    $parsedPodDate = $this->parseDate($podDate);

                    if ($parsedPolDate && $parsedPodDate) {
                        // Calculate transit days from actual website data
                        $transitDays = (strtotime($parsedPodDate) - strtotime($parsedPolDate)) / (60 * 60 * 24);

                        // Sanity check: transit time should be positive and reasonable
                        // West Africa: 7-30 days, South Africa: 20-45 days, East Africa: 25-50 days
                        if ($transitDays > 0 && $transitDays <= 50) {
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
                                'frequency_per_week' => 1.0,
                                'frequency_per_month' => 4.0,
                                'transit_days' => (int) $transitDays,
                                'next_sailing_date' => $parsedPolDate,
                                'data_source' => 'website_table',
                                'source_url' => 'https://sallaumlines.com/schedules/europe-to-west-and-south-africa/'
                            ];

                            Log::info("Sallaum Lines: Created schedule", [
                                'vessel' => $vesselName,
                                'voyage' => $voyageNo,
                                'pol_date' => $parsedPolDate,
                                'pod_date' => $parsedPodDate,
                                'transit_days' => $transitDays
                            ]);
                        } else {
                            Log::warning("Sallaum Lines: Invalid transit time for {$vesselName} ({$voyageNo}): {$transitDays} days", [
                                'vessel' => $vesselName,
                                'voyage' => $voyageNo,
                                'pol_date' => $parsedPolDate,
                                'pod_date' => $parsedPodDate
                            ]);
                        }
                    }
                } else {
                    Log::info("Sallaum Lines: Vessel {$vesselName} ({$voyageNo}) does not serve {$polCode}->{$podCode} route", [
                        'has_pol_date' => isset($polDatesByVoyage[$voyageNo]),
                        'has_pod_date' => isset($podDatesByVoyage[$voyageNo])
                    ]);
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
            $freq = trim($match[1]);
            if (stripos($freq, 'weekly') !== false) {
                $schedule['frequency_per_week'] = 1.0;
                $schedule['frequency_per_month'] = 4.0;
            } elseif (stripos($freq, 'monthly') !== false) {
                $schedule['frequency_per_week'] = 0.25;
                $schedule['frequency_per_month'] = 1.0;
            }
        } elseif (preg_match('/(weekly|monthly|daily)/i', $block, $match)) {
            $freq = strtolower($match[1]);
            if ($freq === 'weekly') {
                $schedule['frequency_per_week'] = 1.0;
                $schedule['frequency_per_month'] = 4.0;
            } elseif ($freq === 'monthly') {
                $schedule['frequency_per_week'] = 0.25;
                $schedule['frequency_per_month'] = 1.0;
            }
        }
        
        // Extract transit time
        if (preg_match('/transit[:\s]+(\d+)\s*(days?|hours?)/i', $block, $match)) {
            $schedule['transit_days'] = (int)$match[1];
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
                    'frequency_per_week' => 1.0,
                    'frequency_per_month' => 4.0,
                    'transit_days' => 21,
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
        // Source: https://sallaumlines.com/schedules/europe-to-west-and-south-africa/
        $portNameMapping = [
            // POLs (Origins)
            'ANR' => 'Antwerp',
            'ZEE' => 'Zeebrugge',
            'FLU' => 'Amsterdam', // Flushing is close to Amsterdam on Sallaum's schedule
            
            // PODs (Destinations) - West Africa
            'ABJ' => 'Abidjan',
            'CKY' => 'Conakry',
            'COO' => 'Cotonou',
            'DKR' => 'Dakar',
            'DLA' => 'Douala',
            'LOS' => 'Lagos',
            'LFW' => 'Lome',
            'PNR' => 'Pointe Noire',
            
            // PODs (Destinations) - East Africa
            'DAR' => 'Dar es Salaam',
            'MBA' => 'Mombasa',
            
            // PODs (Destinations) - South Africa
            'DUR' => 'Durban',
            'ELS' => 'East London',
            'PLZ' => 'Port Elizabeth',
            'WVB' => 'Walvis Bay',
        ];
        
        $polName = $portNameMapping[$polCode] ?? $polCode;
        $podName = $portNameMapping[$podCode] ?? $podCode;
        
        // Check if both POL and POD appear in the schedule table
        $hasPol = stripos($content, $polName) !== false;
        $hasPod = stripos($content, $podName) !== false;
        $hasTable = stripos($content, '<table') !== false;
        
        return $hasPol && $hasPod && $hasTable;
    }

    public function extractSchedules(string $pol, string $pod): array
    {
        return $this->fetchRealSchedules($pol, $pod);
    }

    public function getCarrierCode(): string
    {
        return 'SALLAUM';
    }

    public function getUpdateFrequency(): string
    {
        return 'daily'; // Sallaum updates their schedules daily
    }

    public function getLastUpdate(): ?\DateTime
    {
        return new \DateTime(); // For now, return current time
    }

    /**
     * Find vessel-specific dates in the HTML table without assuming sequential column mapping
     */
    private function findVesselDatesInTable(string $vesselName, string $voyageNo, array $allRows, string $polName, string $podName): ?array
    {
        // Strategy 1: Try to find vessel name in HTML and extract nearby dates
        $vesselDates = $this->findVesselDatesByPattern($vesselName, $voyageNo, $allRows, $polName, $podName);
        
        if ($vesselDates) {
            return $vesselDates;
        }
        
        // Strategy 2: Try sequential mapping but with validation
        $vesselDates = $this->trySequentialMappingWithValidation($vesselName, $voyageNo, $allRows, $polName, $podName);
        
        if ($vesselDates && $this->validateVesselRoute($vesselName, $voyageNo, $polCode, $podCode, $vesselDates['transit_days'])) {
            return $vesselDates;
        }
        
        // If no valid dates found, return null
        return null;
    }

    /**
     * Find vessel dates by searching for vessel name patterns in the HTML
     */
    private function findVesselDatesByPattern(string $vesselName, string $voyageNo, array $allRows, string $polName, string $podName): ?array
    {
        // Look for vessel name and voyage number in the HTML
        $vesselPattern = preg_quote($vesselName, '/');
        $voyagePattern = preg_quote($voyageNo, '/');
        
        foreach ($allRows[1] as $rowHtml) {
            // Check if this row contains the vessel name or voyage number
            if (preg_match("/{$vesselPattern}|{$voyagePattern}/i", $rowHtml)) {
                // Extract dates from this row
                $dates = $this->extractDatesFromRow($rowHtml);
                if (count($dates) >= 2) {
                    // Try to match with POL/POD names
                    $polDate = $this->findDateForPort($dates, $polName);
                    $podDate = $this->findDateForPort($dates, $podName);
                    
                    if ($polDate && $podDate) {
                        return [
                            'pol_date' => $polDate,
                            'pod_date' => $podDate
                        ];
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * Try sequential mapping but with validation to prevent incorrect assignments
     */
    private function trySequentialMappingWithValidation(string $vesselName, string $voyageNo, array $allRows, string $polName, string $podName): ?array
    {
        // Get vessel index
        $vesselIndex = $this->getVesselIndex($vesselName);
        if ($vesselIndex === null) {
            return null;
        }
        
        // Extract dates by column (existing logic)
        $polDatesByColumn = [];
        $podDatesByColumn = [];
        
        foreach ($allRows[1] as $rowHtml) {
            if (stripos($rowHtml, $polName) !== false) {
                preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $rowHtml, $cells);
                foreach ($cells[1] as $colIndex => $cellContent) {
                    $dateText = $this->extractDateFromCell($cellContent);
                    if ($dateText) {
                        $polDatesByColumn[$colIndex] = $dateText;
                    }
                }
            }
            
            if (stripos($rowHtml, $podName) !== false) {
                preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $rowHtml, $cells);
                foreach ($cells[1] as $colIndex => $cellContent) {
                    $dateText = $this->extractDateFromCell($cellContent);
                    if ($dateText) {
                        $podDatesByColumn[$colIndex] = $dateText;
                    }
                }
            }
        }
        
        $polDate = $polDatesByColumn[$vesselIndex] ?? null;
        $podDate = $podDatesByColumn[$vesselIndex] ?? null;
        
        if ($polDate && $podDate) {
            return [
                'pol_date' => $polDate,
                'pod_date' => $podDate
            ];
        }
        
        return null;
    }


    /**
     * Get vessel index in the vessels array
     */
    private function getVesselIndex(string $vesselName): ?int
    {
        // This would need to be passed from the calling method
        // For now, return null to prevent incorrect mapping
        return null;
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

    /**
     * Extract dates from a table row
     */
    private function extractDatesFromRow(string $rowHtml): array
    {
        $dates = [];
        
        // Extract all cells from the row
        preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $rowHtml, $cells);
        
        foreach ($cells[1] as $cellContent) {
            $dateText = $this->extractDateFromCell($cellContent);
            if ($dateText) {
                $dates[] = $dateText;
            }
        }
        
        return $dates;
    }

    /**
     * Find date for a specific port from a list of dates
     */
    private function findDateForPort(array $dates, string $portName): ?string
    {
        // For now, just return the first date if we have any
        // This could be enhanced to match port names more intelligently
        if (empty($dates)) {
            return null;
        }
        
        // Try to parse the first date properly
        $dateText = $dates[0];
        $parsedDate = $this->parseDate($dateText);
        
        return $parsedDate ?: null;
    }

}


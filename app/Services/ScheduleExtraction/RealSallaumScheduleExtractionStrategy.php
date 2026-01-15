<?php

namespace App\Services\ScheduleExtraction;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Port;

class RealSallaumScheduleExtractionStrategy extends RealDataExtractionStrategy
{
    // Cache for parsed schedules to avoid re-parsing the same HTML multiple times
    private static ?array $cachedSchedules = null;
    private static ?string $cachedHtml = null;
    private static ?int $cacheTimestamp = null;
    private const CACHE_TTL = 300; // 5 minutes cache

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
            // Check cache first (only if cache is fresh)
            if (self::$cachedSchedules !== null && 
                self::$cacheTimestamp !== null && 
                (time() - self::$cacheTimestamp) < self::CACHE_TTL) {
                // Filter cached schedules by requested POL/POD
                return array_filter(self::$cachedSchedules, function($schedule) use ($polCode, $podCode) {
                    return ($schedule['pol_code'] ?? '') === $polCode && 
                           ($schedule['pod_code'] ?? '') === $podCode;
                });
            }
            
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
                
                // NEW: Detect HTML structure changes
                if ($this->detectHtmlStructureChange($html)) {
                    Log::error("Sallaum Lines: HTML structure may have changed! Manual review needed.");
                }
                
                Log::info("Sallaum Lines: Fetched schedule page, parsing ALL schedules from table");
                
                // Parse ALL schedules from the table (not just one POL/POD pair)
                $allSchedules = $this->parseSallaumScheduleTableAllRoutes($html);
                
                // Cache the results
                self::$cachedSchedules = $allSchedules;
                self::$cachedHtml = $html;
                self::$cacheTimestamp = time();
                
                // Filter by requested POL/POD
                return array_filter($allSchedules, function($schedule) use ($polCode, $podCode) {
                    return ($schedule['pol_code'] ?? '') === $polCode && 
                           ($schedule['pod_code'] ?? '') === $podCode;
                });
            }
            
        } catch (\Exception $e) {
            Log::error("Sallaum Lines: Failed to fetch real data for {$polCode}->{$podCode}: " . $e->getMessage());
        }
        
        return [];
    }

    /**
     * Parse ALL schedules from Sallaum table - extracts all POL/POD combinations
     * This is more efficient than parsing per route
     */
    protected function parseSallaumScheduleTableAllRoutes(string $html): array
    {
        $allSchedules = [];
        
        try {
            // Map port codes to Sallaum's port names
            $portNameMapping = [
                // POLs (Origins) - Only Antwerp and Zeebrugge for Sallaum
                'ANR' => 'Antwerp',
                'ZEE' => 'Zeebrugge',
                
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
            
            // Reverse mapping: port name => code
            $portCodeMapping = array_flip($portNameMapping);
            
            // Extract table content - read by COLUMNS
            if (preg_match_all('/<table[^>]*>(.*?)<\/table>/is', $html, $tableMatches)) {
                foreach ($tableMatches[1] as $tableHtml) {
                    // Preprocess HTML to handle self-closing <td> tags
                    $tableHtml = preg_replace('/<td([^>]*)\s*\/>/', '<td$1></td>', $tableHtml);
                    $tableHtml = preg_replace('/<td([^>]*)>\s*(?=<td|<\/tr)/', '<td$1></td>', $tableHtml);
                    
                    // Use DOMDocument for robust HTML parsing
                    libxml_use_internal_errors(true);
                    $dom = new \DOMDocument();
                    @$dom->loadHTML('<?xml encoding="UTF-8">' . $tableHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                    libxml_clear_errors();
                    libxml_use_internal_errors(false);
                    
                    $xpath = new \DOMXPath($dom);
                    
                    // Get all rows
                    $rows = $xpath->query('//tr');
                    if ($rows->length < 3) {
                        continue;
                    }
                    
                    // First row: vessel names (skip first cell which is header)
                    $vessels = [];
                    $firstRow = $rows->item(0);
                    if ($firstRow) {
                        $cells = $xpath->query('.//td | .//th', $firstRow);
                        foreach ($cells as $index => $cell) {
                            if ($index === 0) continue; // Skip first column (header)
                            $vesselName = trim($cell->textContent);
                            if (!empty($vesselName) && $vesselName !== 'ETA' && !preg_match('/^\d{4}$/', $vesselName)) {
                                $vessels[] = $vesselName;
                            }
                        }
                    }
                    
                    // Second row: voyage numbers (skip first cell)
                    $voyageNumbers = [];
                    $secondRow = $rows->item(1);
                    if ($secondRow) {
                        $cells = $xpath->query('.//td | .//th', $secondRow);
                        foreach ($cells as $index => $cell) {
                            if ($index === 0) continue; // Skip first column
                            $voyageNo = trim($cell->textContent);
                            if (preg_match('/^(\d{2}[A-Z]{2}\d{2})$/', $voyageNo, $match)) {
                                $voyageNumbers[] = $match[1];
                            } else {
                                $voyageNumbers[] = null; // Keep alignment
                            }
                        }
                    }
                    
                    // Ensure voyage numbers array matches vessels array length
                    while (count($voyageNumbers) < count($vessels)) {
                        $voyageNumbers[] = null;
                    }
                    
                    Log::info("Sallaum Lines: Found " . count($vessels) . " vessels", ['vessels' => $vessels]);
                    Log::info("Sallaum Lines: Found " . count($voyageNumbers) . " voyage numbers", ['voyages' => $voyageNumbers]);
                    
                    // Build port name to row index mapping for ALL POLs and PODs
                    $polRowIndices = []; // Row index => POL code
                    $podRowIndices = []; // Row index => POD code
                    
                    // Scan all rows to find POL and POD rows
                    for ($rowIndex = 2; $rowIndex < $rows->length; $rowIndex++) {
                        $row = $rows->item($rowIndex);
                        $firstCell = $xpath->query('.//td[1] | .//th[1]', $row)->item(0);
                        if (!$firstCell) continue;
                        
                        $portName = trim($firstCell->textContent);
                        $portNameUpper = strtoupper($portName);
                        
                        // Check if this is a POL row (Antwerp or Zeebrugge)
                        if ($portNameUpper === 'ANTWERP' || $portNameUpper === 'ZEEBRUGGE') {
                            $portCode = $portCodeMapping[$portName] ?? null;
                            if ($portCode && in_array($portCode, ['ANR', 'ZEE'])) {
                                $polRowIndices[$rowIndex] = $portCode;
                            }
                        }
                        
                        // Check if this is a POD row (all other ports in the mapping)
                        foreach ($portNameMapping as $code => $name) {
                            if (in_array($code, ['ANR', 'ZEE'])) continue; // Skip POLs
                            if (stripos($portName, $name) !== false || $portNameUpper === strtoupper($name)) {
                                $podRowIndices[$rowIndex] = $code;
                                break;
                            }
                        }
                    }
                    
                    Log::info("Sallaum Lines: Found " . count($polRowIndices) . " POL rows and " . count($podRowIndices) . " POD rows");
                    
                    // For each vessel column, extract dates from ALL POL and POD rows
                    // Column index 0 = port names, Column index 1+ = vessel columns
                    for ($colIndex = 1; $colIndex <= count($vessels); $colIndex++) {
                        $vesselName = $vessels[$colIndex - 1] ?? null;
                        $voyageNo = $voyageNumbers[$colIndex - 1] ?? null;
                        
                        if (!$vesselName || !$voyageNo) {
                            continue;
                        }
                        
                        // Extract ALL dates from Antwerp row (focus on ANR only as per user requirement)
                        // The table may have MULTIPLE cells for the same vessel (one per date)
                        // We need to find ALL cells that have this voyage number in their headers
                        $antwerpDates = [];
                        $antwerpRowIndex = null;
                        foreach ($polRowIndices as $rowIndex => $polCodeInRow) {
                            if ($polCodeInRow === 'ANR') {
                                $antwerpRowIndex = $rowIndex;
                                $row = $rows->item($rowIndex);
                                
                                // Find ALL cells in this row that have the voyage number in their headers attribute
                                // The headers attribute contains the voyage code (e.g., "26PA01-date")
                                $allCells = $xpath->query('.//td | .//th', $row);
                                foreach ($allCells as $cell) {
                                    $cellHtml = $dom->saveHTML($cell);
                                    // Check if this cell belongs to this vessel/voyage by checking headers attribute
                                    // Headers format: "...26PA01-date..." - must have voyage number followed by "-date"
                                    // This ensures we only match cells that are actually date cells for this voyage
                                    // Not cells that just happen to have the voyage in nested structure
                                    $hasVoyageDateInHeaders = preg_match('/headers=["\'][^"\']*' . preg_quote($voyageNo, '/') . '-date[^"\']*["\']/i', $cellHtml);
                                    if ($hasVoyageDateInHeaders) {
                                        // Also verify the cell actually contains a date (not just nested empty cells)
                                        $allDates = $this->extractAllDatesFromCell($cellHtml);
                                        if (!empty($allDates)) {
                                            foreach ($allDates as $dateText) {
                                                $parsedDate = $this->parseDate($dateText);
                                                if ($parsedDate) {
                                                    $antwerpDates[] = $parsedDate;
                                                }
                                            }
                                        }
                                    }
                                }
                                
                                break; // Only process Antwerp
                            }
                        }
                        
                        // Use only the EARLIEST Antwerp date for this vessel/voyage
                        // This prevents duplicates when multiple cells exist for the same vessel
                        $polDate = null;
                        if (!empty($antwerpDates)) {
                            $antwerpDates = array_unique($antwerpDates); // Remove duplicates
                            sort($antwerpDates); // Sort chronologically
                            $polDate = $antwerpDates[0]; // Use earliest date
                        }
                        
                        if (!$polDate) {
                            continue; // Skip if no Antwerp date found
                        }
                        
                        // Extract ALL POD dates from this column
                        // Use headers attribute to find cells belonging to this vessel/voyage
                        $podDatesByCode = [];
                        foreach ($podRowIndices as $rowIndex => $podCodeInRow) {
                            $row = $rows->item($rowIndex);
                            $allCells = $xpath->query('.//td | .//th', $row);
                            
                            // Find cells that have this voyage number in headers
                            foreach ($allCells as $cell) {
                                $cellHtml = $dom->saveHTML($cell);
                                // Check if this cell belongs to this vessel/voyage
                                if (preg_match('/headers=["\'][^"\']*' . preg_quote($voyageNo, '/') . '[^"\']*["\']/i', $cellHtml)) {
                                    $dateText = $this->extractDateFromCell($cellHtml);
                                    if ($dateText) {
                                        $parsedPodDate = $this->parseDate($dateText);
                                        if ($parsedPodDate) {
                                            // If multiple dates found for same POD, use the one that makes sense transit-wise
                                            // (closest to POL date but after it)
                                            if (!isset($podDatesByCode[$podCodeInRow]) || 
                                                abs(strtotime($parsedPodDate) - strtotime($polDate)) < 
                                                abs(strtotime($podDatesByCode[$podCodeInRow]) - strtotime($polDate))) {
                                                $podDatesByCode[$podCodeInRow] = $parsedPodDate;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        
                        // Create schedules using the SINGLE Antwerp date with each POD
                        foreach ($podDatesByCode as $podCodeInRow => $parsedPodDate) {
                            // Calculate transit days
                            $transitDays = (strtotime($parsedPodDate) - strtotime($polDate)) / (60 * 60 * 24);

                            // Sanity check: transit time should be positive and reasonable
                            if ($transitDays > 0 && $transitDays <= 50) {
                                $scheduleData = [
                                    'pol_code' => 'ANR', // Always Antwerp
                                    'pod_code' => $podCodeInRow,
                                    'carrier_code' => 'SALLAUM',
                                    'carrier_name' => 'Sallaum Lines',
                                    'service_type' => 'RORO',
                                    'service_name' => "Europe to Africa",
                                    'vessel_name' => $vesselName,
                                    'voyage_number' => $voyageNo,
                                    'ets_pol' => $polDate,
                                    'eta_pod' => $parsedPodDate,
                                    'frequency_per_week' => 1.0,
                                    'frequency_per_month' => 4.0,
                                    'transit_days' => (int) $transitDays,
                                    'next_sailing_date' => $polDate,
                                    'data_source' => 'website_table',
                                    'source_url' => 'https://sallaumlines.com/schedules/europe-to-west-and-south-africa/'
                                ];
                                
                                // Validate before adding
                                if ($this->validateScheduleData($scheduleData)) {
                                    $allSchedules[] = $scheduleData;
                                }
                            }
                        }
                    }
                }
            }
            
            Log::info("Sallaum Lines: Extracted " . count($allSchedules) . " total schedules from table");
            
            // Deduplicate: For each vessel/voyage/POD combination, keep only the one with earliest ETS
            $deduplicated = [];
            foreach ($allSchedules as $schedule) {
                $key = ($schedule['vessel_name'] ?? '') . '_' . 
                       ($schedule['voyage_number'] ?? '') . '_' . 
                       ($schedule['pol_code'] ?? '') . '_' . 
                       ($schedule['pod_code'] ?? '');
                
                if (!isset($deduplicated[$key])) {
                    $deduplicated[$key] = $schedule;
                } else {
                    // Keep the one with earliest ETS date
                    $existingEts = strtotime($deduplicated[$key]['ets_pol'] ?? '9999-12-31');
                    $newEts = strtotime($schedule['ets_pol'] ?? '9999-12-31');
                    if ($newEts < $existingEts) {
                        $deduplicated[$key] = $schedule;
                    }
                }
            }
            $allSchedules = array_values($deduplicated);
            
            Log::info("Sallaum Lines: After deduplication: " . count($allSchedules) . " schedules");
            
        } catch (\Exception $e) {
            Log::error("Sallaum Lines: Failed to parse schedule table: " . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        return $allSchedules;
    }

    /**
     * Legacy method - kept for backward compatibility but now calls parseSallaumScheduleTableAllRoutes
     */
    protected function parseSallaumScheduleTable(string $html, string $polCode, string $podCode): array
    {
        // This method is no longer used - all parsing is done in parseSallaumScheduleTableAllRoutes
        // But we keep it for backward compatibility
        $allSchedules = $this->parseSallaumScheduleTableAllRoutes($html);
        
        // Filter by requested POL/POD
        return array_filter($allSchedules, function($schedule) use ($polCode, $podCode) {
            return ($schedule['pol_code'] ?? '') === $polCode && 
                   ($schedule['pod_code'] ?? '') === $podCode;
        });
    }
    
    protected function extractDateFromCell(string $cellHtml): ?string
    {
        // Extract FIRST date from cell HTML, handling complex nested structure
        // NEW FORMAT: <div class="number"><strong><span class="number-style">DD</span></strong></div> MONTH YEAR
        // OLD FORMAT: <strong>DD</strong> MONTH YEAR
        
        $day = null;
        $monthYear = null;
        
        // Strategy 1: NEW - Extract day from <span class="number-style"> tag
        if (preg_match('/<span[^>]*class="number-style"[^>]*>(\d+)<\/span>/i', $cellHtml, $dayMatch)) {
            $day = $dayMatch[1];
        }
        // Strategy 2: OLD - Extract day from <strong> tag (fallback)
        elseif (preg_match('/<strong[^>]*>(\d+)<\/strong>/i', $cellHtml, $dayMatch)) {
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
    
    /**
     * Extract ALL dates from a cell that may contain multiple dates
     * Used for Antwerp row which may have multiple sailing dates
     */
    protected function extractAllDatesFromCell(string $cellHtml): array
    {
        $dates = [];
        
        // Extract all day numbers from <span class="number-style"> tags
        preg_match_all('/<span[^>]*class="number-style"[^>]*>(\d+)<\/span>/i', $cellHtml, $dayMatches);
        $days = $dayMatches[1] ?? [];
        
        // Extract month and year (should be same for all dates in the cell)
        $monthYear = null;
        $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        foreach ($months as $month) {
            if (stripos($cellHtml, $month) !== false) {
                if (preg_match('/' . $month . '\s*(\d{4})/i', $cellHtml, $yearMatch)) {
                    $monthYear = $month . ' ' . $yearMatch[1];
                    break;
                }
            }
        }
        
        // If we found days and month/year, create date strings
        if (!empty($days) && $monthYear) {
            foreach ($days as $day) {
                $dates[] = $day . ' ' . $monthYear;
            }
        } else {
            // Fallback: try to extract dates using the single date method
            $singleDate = $this->extractDateFromCell($cellHtml);
            if ($singleDate) {
                $dates[] = $singleDate;
            }
        }
        
        return $dates;
    }
    
    protected function parseDate(string $dateString): ?string
    {
        // Parse dates like "2 September 2025" or "21 September 2025"
        try {
            // Remove bold markers and extra spaces
            $dateString = trim(preg_replace('/<[^>]+>/', '', $dateString));
            $dateString = preg_replace('/\s+/', ' ', $dateString);
            
            // Try to parse the date
            $parsed = \DateTime::createFromFormat('j F Y', $dateString);
            if ($parsed) {
                return $parsed->format('Y-m-d');
            }
            
            // Try alternative format
            $parsed = \DateTime::createFromFormat('d F Y', $dateString);
            if ($parsed) {
                return $parsed->format('Y-m-d');
            }
            
        } catch (\Exception $e) {
            Log::warning("Sallaum Lines: Failed to parse date: {$dateString}");
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
        // Only support Antwerp and Zeebrugge for POL (as per user requirement)
        $supportedPols = ['ANR', 'ZEE'];
        
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

    /**
     * Detect if Sallaum's HTML structure has changed
     * This helps alert us when the website format changes and scraping may break
     */
    protected function detectHtmlStructureChange(string $html): bool
    {
        // Check for expected HTML markers
        $hasNewStructure = stripos($html, 'class="number-style"') !== false;
        $hasOldStructure = preg_match('/<strong>\d+<\/strong>\s+\w+\s+\d{4}/', $html);
        $hasTable = stripos($html, '<table') !== false;
        
        // If we don't find either the new or old structure AND there's a table,
        // the HTML structure may have changed again
        if ($hasTable && !$hasNewStructure && !$hasOldStructure) {
            return true; // Structure changed - alert needed!
        }
        
        return false; // Structure looks familiar
    }

    /**
     * Validate schedule data before storing
     * Prevents storing invalid/past schedules
     */
    protected function validateScheduleData(array $schedule): bool
    {
        // Must have a sailing date
        if (!isset($schedule['next_sailing_date'])) {
            Log::warning("Sallaum: Rejecting schedule - missing sailing date", $schedule);
            return false;
        }
        
        // Must be a future sailing (not in the past)
        $sailingDate = strtotime($schedule['next_sailing_date']);
        if ($sailingDate < strtotime('today')) {
            Log::warning("Sallaum: Rejecting past sailing date", [
                'date' => $schedule['next_sailing_date'],
                'vessel' => $schedule['vessel_name'] ?? 'unknown'
            ]);
            return false;
        }
        
        // Transit time must be reasonable (5-50 days for Sallaum routes)
        if (isset($schedule['transit_days'])) {
            if ($schedule['transit_days'] < 5 || $schedule['transit_days'] > 50) {
                Log::warning("Sallaum: Rejecting invalid transit time", [
                    'transit_days' => $schedule['transit_days'],
                    'vessel' => $schedule['vessel_name'] ?? 'unknown',
                    'route' => ($schedule['pol_code'] ?? '?') . '→' . ($schedule['pod_code'] ?? '?')
                ]);
                return false;
            }
        }
        
        return true;
    }

}

